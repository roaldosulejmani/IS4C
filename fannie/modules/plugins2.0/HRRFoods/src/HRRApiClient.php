<?php
/*******************************************************************************

    HRR Foods Plugin — REST Client

    High-level wrapper around the pluggable transport. Handles:
      - base URL composition
      - per-store account-code injection (query/body/header)
      - OAuth2 token attachment (with 401-forced-refresh-and-retry)
      - token-bucket rate limiting
      - retry/backoff for transient failures (408, 429, 5xx, network, JSON parse)
      - redacted logging

    Surface methods (one per HRR endpoint, see HRREndpoints.php).

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRApiClientException extends \RuntimeException {}
class HRRRateLimitedException extends HRRApiClientException {} // retriable
class HRRClientException extends HRRApiClientException {}      // 4xx, not retriable
class HRRTransportException extends HRRApiClientException {}  // network, JSON parse

class HRRApiClient
{
    /** @var array<string,string> */
    private $settings;

    /** @var HRRHttpTransport */
    private $transport;

    /** @var HRRAuth */
    private $auth;

    /** @var HRRStoreMap */
    private $storeMap;

    /** @var callable|null function($level, $msg, $ctx=array()) */
    private $logger;

    /** Token-bucket state. */
    private $bucketCapacity;
    private $bucketTokens;
    private $bucketLastRefill;

    /** HTTP status codes we retry on. */
    private static $retriableStatuses = array(408, 429, 500, 502, 503, 504);

    /**
     * @param array<string,string> $settings HRR* settings
     * @param HRRHttpTransport $transport
     * @param HRRAuth $auth
     * @param HRRStoreMap $storeMap already loaded with settings
     * @param callable|null $logger fn($level, $msg, $ctx=array())
     */
    public function __construct(array $settings, HRRHttpTransport $transport, HRRAuth $auth, HRRStoreMap $storeMap, $logger = null)
    {
        $this->settings = $settings;
        $this->transport = $transport;
        $this->auth = $auth;
        $this->storeMap = $storeMap;
        $this->logger = $logger;

        $rpm = isset($settings['HRRRateLimitPerMinute']) ? (int)$settings['HRRRateLimitPerMinute'] : 60;
        if ($rpm < 1) {
            $rpm = 60;
        }
        $this->bucketCapacity = $rpm;
        $this->bucketTokens = $rpm;
        $this->bucketLastRefill = microtime(true);
    }

    /**
     * Inject a logger. Receives strings at 'debug'/'info'/'warning'/'error'.
     *
     * @param callable $fn
     * @return void
     */
    public function setLogger($fn)
    {
        $this->logger = $fn;
    }

    // ---------------------------------------------------------------
    // Public surface — one method per endpoint
    // ---------------------------------------------------------------

    /**
     * @return array{ok:bool, httpCode:int, body:string, error?:string}
     */
    public function testConnection()
    {
        $url = $this->url(HRREndpoints::HEALTH);
        try {
            $resp = $this->sendWithRetry('GET', $url, array(), null, false, false);
            return array(
                'ok' => $resp['status'] >= 200 && $resp['status'] < 300,
                'httpCode' => $resp['status'],
                'body' => $resp['body'],
            );
        } catch (HRRApiClientException $e) {
            return array(
                'ok' => false,
                'httpCode' => 0,
                'body' => '',
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * @param string $sinceIso ISO-8601 timestamp (or empty for full pull)
     * @param int $page 1-based page number
     * @param int $size page size
     * @param string|null $accountCode per-store account code, or null
     * @return array{items:array<int,array>, nextPage:?int, raw:array}
     */
    public function fetchCatalogPage($sinceIso, $page, $size, $accountCode = null)
    {
        $path = HRREndpoints::CATALOG_PRODUCTS;
        $query = array(
            'page' => max(1, (int)$page),
            'size' => max(1, (int)$size),
        );
        if ($sinceIso !== '') {
            $query['since'] = $sinceIso;
        }
        $url = $this->url($path, $query, $accountCode);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        $raw = HRRJson::decodeOr($resp['body'], array());
        $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();
        $nextPage = isset($raw['nextPage']) ? (int)$raw['nextPage'] : null;
        return array(
            'items' => $items,
            'nextPage' => $nextPage,
            'raw' => is_array($raw) ? $raw : array(),
        );
    }

    /**
     * @param string $upc
     * @param string|null $accountCode
     * @return array
     */
    public function fetchProduct($upc, $accountCode = null)
    {
        $path = HRREndpoints::fill(HRREndpoints::CATALOG_PRODUCT, array('upc' => $this->safeUpc($upc)));
        $url = $this->url($path, array(), $accountCode);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        return HRRJson::decodeOr($resp['body'], array());
    }

    /**
     * @param string $upc
     * @param string $accountCode REQUIRED for price
     * @return array
     */
    public function fetchPrice($upc, $accountCode)
    {
        $path = HRREndpoints::fill(HRREndpoints::CATALOG_PRODUCT_PRICE, array('upc' => $this->safeUpc($upc)));
        $url = $this->url($path, array(), $accountCode);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        return HRRJson::decodeOr($resp['body'], array());
    }

    /**
     * @param string $upc
     * @return array{url?:string, updated_at?:string}
     */
    public function fetchImage($upc)
    {
        $path = HRREndpoints::fill(HRREndpoints::CATALOG_PRODUCT_IMAGE, array('upc' => $this->safeUpc($upc)));
        $url = $this->url($path, array(), null);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        return HRRJson::decodeOr($resp['body'], array());
    }

    /**
     * Submit a PO. The body must already include the top-level accountCode.
     *
     * @param array $poBody
     * @return array{vendorOrderID?:string, acceptedAt?:string, warnings?:array}
     */
    public function submitPurchaseOrder(array $poBody)
    {
        $url = $this->url(HRREndpoints::PURCHASE_ORDERS);
        $headers = array(
            'accept' => 'application/json',
            'content-type' => 'application/json',
        );
        if (isset($poBody['accountCode']) && is_string($poBody['accountCode']) && $poBody['accountCode'] !== '') {
            $headers['X-HRR-Account'] = $poBody['accountCode'];
        }
        $body = HRRJson::encode($poBody);
        $resp = $this->sendWithRetry('POST', $url, $headers, $body, true, true);
        return HRRJson::decodeOr($resp['body'], array());
    }

    /**
     * @param string $sinceIso
     * @param string|null $accountCode
     * @return array{items:array<int,array>, raw:array}
     */
    public function fetchInvoices($sinceIso, $accountCode = null)
    {
        $query = array();
        if ($sinceIso !== '') {
            $query['since'] = $sinceIso;
        }
        $url = $this->url(HRREndpoints::INVOICES, $query, $accountCode);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        $raw = HRRJson::decodeOr($resp['body'], array());
        $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();
        return array(
            'items' => $items,
            'raw' => is_array($raw) ? $raw : array(),
        );
    }

    /**
     * @param string $invoiceId
     * @param string $accountCode REQUIRED
     * @return array
     */
    public function fetchInvoice($invoiceId, $accountCode)
    {
        $path = HRREndpoints::fill(HRREndpoints::INVOICE, array('invoiceId' => $invoiceId));
        $url = $this->url($path, array(), $accountCode);
        $resp = $this->sendWithRetry('GET', $url, array('accept' => 'application/json'), null, true, false);
        return HRRJson::decodeOr($resp['body'], array());
    }

    // ---------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------

    /**
     * Compose a full URL from base + path + query, injecting account code.
     *
     * @param string $path
     * @param array<string,scalar> $query
     * @param string|null $accountCode
     * @return string
     */
    private function url($path, array $query = array(), $accountCode = null)
    {
        $base = isset($this->settings['HRRApiBaseUrl']) ? trim($this->settings['HRRApiBaseUrl']) : '';
        if ($base === '') {
            throw new HRRAuthException('HRRApiBaseUrl is not configured.');
        }
        $base = rtrim($base, '/');
        $path = '/' . ltrim((string)$path, '/');

        if ($accountCode === null || $accountCode === '') {
            $accountCode = $this->storeMap->accountCodeForDefault(
                isset($this->settings['HRRDefaultStoreID']) ? (int)$this->settings['HRRDefaultStoreID'] : 0
            );
        }
        if ($accountCode !== null && $accountCode !== '' && $this->isGetMethodForPath($path)) {
            // Per-store routing: ?store={accountCode} on all read endpoints.
            $query['store'] = $accountCode;
        }

        $url = $base . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /**
     * Most read endpoints accept ?store=. POST /purchase-orders uses
     * accountCode in the body instead. This is a best-guess split; if HRR
     * publishes a different convention, change here.
     *
     * @param string $path
     * @return bool
     */
    private function isGetMethodForPath($path)
    {
        return true; // always; POSTs handle accountCode at the caller
    }

    /**
     * Send with retry/backoff and OAuth handling.
     *
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers
     * @param string|null $body
     * @param bool $auth
     * @param bool $isJson
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function sendWithRetry($method, $url, array $headers, $body, $auth, $isJson)
    {
        $maxRetries = isset($this->settings['HRRMaxRetries']) ? (int)$this->settings['HRRMaxRetries'] : 3;
        if ($maxRetries < 0) {
            $maxRetries = 0;
        }
        $timeout = isset($this->settings['HRRRequestTimeoutSec']) ? (int)$this->settings['HRRRequestTimeoutSec'] : 30;

        $retriedAuth = false;
        $attempt = 0;
        while (true) {
            $this->waitForToken();

            $reqHeaders = $headers;
            if ($auth) {
                $token = $this->auth->getToken();
                $reqHeaders['Authorization'] = 'Bearer ' . $token;
            }

            $start = microtime(true);
            $resp = $this->transport->request($method, $url, $reqHeaders, $body, $timeout);
            $elapsedMs = (int)round((microtime(true) - $start) * 1000);
            $status = (int)$resp['status'];
            $this->log('debug', sprintf('%s %s -> %d (%dms)', $method, $this->safeUrl($url), $status, $elapsedMs), $resp);

            // 401 -> forced refresh + one retry
            if ($status === 401 && $auth && !$retriedAuth) {
                $retriedAuth = true;
                $this->log('info', 'Received 401, forcing token refresh and retrying');
                $this->auth->forceRefresh();
                continue;
            }

            // 429 -> back off; mark as retriable
            if ($status === 429) {
                $this->bucketTokens = max(0, (int)floor($this->bucketCapacity / 2));
                if ($attempt >= $maxRetries) {
                    throw new HRRRateLimitedException('Rate limited (429) after ' . $attempt . ' retries');
                }
                $this->sleepBackoff($attempt, true);
                $attempt++;
                continue;
            }

            // Other retriable statuses
            if (in_array($status, self::$retriableStatuses, true) || $status === 0) {
                if ($attempt >= $maxRetries) {
                    throw new HRRTransportException(sprintf(
                        'HTTP %d after %d retries on %s %s',
                        $status, $attempt, $method, $this->safeUrl($url)
                    ));
                }
                $this->sleepBackoff($attempt, false);
                $attempt++;
                continue;
            }

            // 2xx -> return
            if ($status >= 200 && $status < 300) {
                if ($isJson) {
                    $decoded = json_decode((string)$resp['body'], true);
                    if ($decoded === null && trim((string)$resp['body']) !== 'null' && trim((string)$resp['body']) !== '') {
                        throw new HRRTransportException('Response body was not valid JSON: ' . substr((string)$resp['body'], 0, 200));
                    }
                }
                return $resp;
            }

            // 4xx (except 401/408/429) -> not retriable
            throw new HRRClientException(sprintf(
                'HTTP %d from %s %s: %s',
                $status, $method, $this->safeUrl($url), substr((string)$resp['body'], 0, 500)
            ));
        }
    }

    /**
     * Token-bucket wait. Refills at HRRRateLimitPerMinute; sleeps on empty bucket.
     *
     * @return void
     */
    private function waitForToken()
    {
        $now = microtime(true);
        $elapsed = $now - $this->bucketLastRefill;
        $refill = ($elapsed / 60.0) * $this->bucketCapacity;
        if ($refill >= 1) {
            $this->bucketTokens = min($this->bucketCapacity, $this->bucketTokens + (int)floor($refill));
            $this->bucketLastRefill = $now;
        }
        if ($this->bucketTokens <= 0) {
            $wait = (60.0 / max(1, $this->bucketCapacity));
            $this->log('debug', sprintf('Rate limit bucket empty, sleeping %.2fs', $wait));
            usleep((int)($wait * 1e6));
        }
        $this->bucketTokens = max(0, $this->bucketTokens - 1);
    }

    /**
     * Exponential backoff: 1s, 2s, 4s, 8s, 16s with ±20% jitter.
     * 429 doubles the wait.
     *
     * @param int $attempt
     * @param bool $is429
     * @return void
     */
    private function sleepBackoff($attempt, $is429)
    {
        $base = (1 << min($attempt, 4)) * ($is429 ? 2 : 1);
        $jitter = $base * (mt_rand(-20, 20) / 100.0);
        $sleep = max(0, (int)round(($base + $jitter) * 1e6));
        $this->log('debug', sprintf('Backoff: %dms (attempt %d%s)', (int)round($sleep / 1000), $attempt, $is429 ? ', 429' : ''));
        usleep($sleep);
    }

    /**
     * Strip the access_token from a URL for logging (none should ever be there,
     * but defensively guard against future refactors).
     *
     * @param string $url
     * @return string
     */
    private function safeUrl($url)
    {
        return preg_replace('/access_token=[^&]+/', 'access_token=[REDACTED]', (string)$url);
    }

    /**
     * Sanitize UPC for use in a URL path segment.
     *
     * @param string $upc
     * @return string
     */
    private function safeUpc($upc)
    {
        return urlencode(trim((string)$upc));
    }

    /**
     * Strip secrets from a response body for logging.
     *
     * @param array $resp
     * @return array
     */
    private function redactResponse($resp)
    {
        if (!isset($resp['body'])) {
            return $resp;
        }
        $body = (string)$resp['body'];
        $patterns = array(
            '/"access_token"\s*:\s*"[^"]*"/i',
            '/"client_secret"\s*:\s*"[^"]*"/i',
        );
        $resp['body'] = preg_replace($patterns, '"[REDACTED]":"[REDACTED]"', $body);
        return $resp;
    }

    /**
     * @param string $level
     * @param string $msg
     * @param array $ctx
     * @return void
     */
    private function log($level, $msg, array $ctx = array())
    {
        if (!$this->logger) {
            return;
        }
        if (isset($ctx['body'])) {
            $ctx = $this->redactResponse($ctx);
        }
        call_user_func($this->logger, $level, $msg, $ctx);
    }
}
