<?php
/*******************************************************************************

    HRR Foods Plugin — OAuth2 Token Cache

    In-memory + disk-cached access token with auto-refresh. Each PHP process
    (CLI cron or web request) gets one token; the disk cache avoids every
    cron task paying the round-trip.

    File: noauto/.token-cache.json  (chmod 0600)
    Shape: { access_token: "...", token_type: "Bearer", expires_at: "ISO-8601", scope: "..." }

    Redaction: nothing inside this file should ever be logged.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRAuthException extends \RuntimeException {}

class HRRAuth
{
    /** Refresh within this many seconds of expiry. */
    const REFRESH_GRACE_SECONDS = 60;

    /** @var array<string,string> */
    private $settings;

    /** @var HRRHttpTransport */
    private $transport;

    /** @var string */
    private $cacheFile;

    /** @var array|null {access_token, token_type, expires_at, scope} */
    private $token = null;

    /**
     * @param array<string,string> $settings HRR* settings (from FanniePlugin::getSettings())
     * @param HRRHttpTransport $transport
     * @param string $cacheFile absolute path to the token cache file
     */
    public function __construct(array $settings, HRRHttpTransport $transport, $cacheFile)
    {
        $this->settings = $settings;
        $this->transport = $transport;
        $this->cacheFile = (string)$cacheFile;
    }

    /**
     * Get a valid access token. Refreshes if expired or within the grace window.
     *
     * @return string the access token (suitable for Authorization: Bearer ...)
     * @throws HRRAuthException
     */
    public function getToken()
    {
        $now = time();
        if ($this->token !== null) {
            $expires = strtotime($this->token['expires_at']);
            if ($expires !== false && $expires - $now > self::REFRESH_GRACE_SECONDS) {
                return $this->token['access_token'];
            }
        }
        $loaded = $this->loadFromDisk();
        if ($loaded) {
            $expires = strtotime($loaded['expires_at']);
            if ($expires !== false && $expires - $now > self::REFRESH_GRACE_SECONDS) {
                $this->token = $loaded;
                return $this->token['access_token'];
            }
        }
        $fresh = $this->fetchNewToken();
        $this->token = $fresh;
        $this->saveToDisk($fresh);
        return $fresh['access_token'];
    }

    /**
     * Force a refresh (e.g. after a 401). Bypasses the grace window.
     *
     * @return string the new access token
     * @throws HRRAuthException
     */
    public function forceRefresh()
    {
        $fresh = $this->fetchNewToken();
        $this->token = $fresh;
        $this->saveToDisk($fresh);
        return $fresh['access_token'];
    }

    /**
     * @return array{access_token:string, token_type:string, expires_at:string, scope:string}
     * @throws HRRAuthException
     */
    private function fetchNewToken()
    {
        $url = isset($this->settings['HRRTokenUrl']) ? trim($this->settings['HRRTokenUrl']) : '';
        $clientId = isset($this->settings['HRROAuthClientId']) ? $this->settings['HRROAuthClientId'] : '';
        $clientSecret = isset($this->settings['HRROAuthClientSecret']) ? $this->settings['HRROAuthClientSecret'] : '';
        $scope = isset($this->settings['HRROAuthScope']) ? $this->settings['HRROAuthScope'] : '';
        if ($url === '' || $clientId === '' || $clientSecret === '') {
            throw new HRRAuthException('OAuth not configured: HRRTokenUrl, HRROAuthClientId, HRROAuthClientSecret are required.');
        }

        // client_credentials grant, form-encoded
        $body = http_build_query(array(
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => $scope,
        ));

        $response = $this->transport->request(
            'POST',
            $url,
            array(
                'accept' => 'application/json',
                'content-type' => 'application/x-www-form-urlencoded',
            ),
            $body,
            isset($this->settings['HRRRequestTimeoutSec']) ? (int)$this->settings['HRRRequestTimeoutSec'] : 30
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new HRRAuthException(sprintf(
                'Token endpoint returned HTTP %d: %s',
                $response['status'],
                $this->redact(substr((string)$response['body'], 0, 500))
            ));
        }

        $decoded = HRRJson::decodeOr($response['body'], array());
        if (!isset($decoded['access_token'])) {
            throw new HRRAuthException('Token response missing access_token field.');
        }
        $expiresIn = isset($decoded['expires_in']) ? (int)$decoded['expires_in'] : 3600;
        if ($expiresIn <= 0) {
            $expiresIn = 3600;
        }
        $expiresAt = date('c', time() + $expiresIn);
        return array(
            'access_token' => (string)$decoded['access_token'],
            'token_type' => isset($decoded['token_type']) ? (string)$decoded['token_type'] : 'Bearer',
            'expires_at' => $expiresAt,
            'scope' => isset($decoded['scope']) ? (string)$decoded['scope'] : $scope,
        );
    }

    /**
     * @return array|null
     */
    private function loadFromDisk()
    {
        if (!is_file($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = HRRJson::decodeOr($raw, null);
        if (!is_array($decoded) || !isset($decoded['access_token'], $decoded['expires_at'])) {
            return null;
        }
        return $decoded;
    }

    /**
     * @param array $token
     * @return void
     */
    private function saveToDisk(array $token)
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents($this->cacheFile, HRRJson::encode($token));
        @chmod($this->cacheFile, 0600);
    }

    /**
     * Strip known secret fields from a string before logging.
     *
     * @param string $s
     * @return string
     */
    private function redact($s)
    {
        $s = (string)$s;
        $patterns = array(
            '/"client_secret"\s*:\s*"[^"]*"/i',
            '/"access_token"\s*:\s*"[^"]*"/i',
            '/client_secret=[^&\s]+/i',
            '/access_token=[^&\s]+/i',
            '/Authorization:\s*Bearer\s+\S+/i',
        );
        $replacements = array(
            '"client_secret":"[REDACTED]"',
            '"access_token":"[REDACTED]"',
            'client_secret=[REDACTED]',
            'access_token=[REDACTED]',
            'Authorization: Bearer [REDACTED]',
        );
        return preg_replace($patterns, $replacements, $s);
    }
}
