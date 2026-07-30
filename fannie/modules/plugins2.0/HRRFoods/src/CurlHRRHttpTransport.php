<?php
/*******************************************************************************

    HRR Foods Plugin — Default cURL HTTP Transport

    Mirrors the cURL patterns used by UIGTask.php and CpwPriceTask.php:
      - follow redirects
      - set a real User-Agent
      - return both the body and the status code

    SSL verification is enabled by default. Pass `dev` mode through the
    plugin settings if you need to disable it (only for local mocks).

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class CurlHRRHttpTransport implements HRRHttpTransport
{
    /** @var bool */
    private $verifySsl;

    public function __construct($verifySsl = true)
    {
        $this->verifySsl = (bool)$verifySsl;
    }

    public function request($method, $url, array $headers, $body, $timeout)
    {
        $method = strtoupper((string)$method);
        $timeout = max(1, (int)$timeout);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }

        $headerLines = array();
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        if (!empty($headerLines)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            curl_close($ch);
            // Status 0 signals a transport-level failure (network/DNS/timeout).
            return array(
                'status' => 0,
                'headers' => array(),
                'body' => 'cURL error (' . $errno . '): ' . $errstr,
            );
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $bodyOut = substr($raw, $headerSize);
        $headersOut = $this->parseHeaders($rawHeaders);

        return array(
            'status' => $httpCode,
            'headers' => $headersOut,
            'body' => (string)$bodyOut,
        );
    }

    /**
     * Parse raw HTTP headers into a name => value array. Last value wins for
     * duplicate names (Set-Cookie would be a special case; we don't use it).
     *
     * @param string $raw
     * @return array<string,string>
     */
    private function parseHeaders($raw)
    {
        $out = array();
        $lines = preg_split('/\r?\n/', (string)$raw);
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            if ($name === '') {
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }
}
