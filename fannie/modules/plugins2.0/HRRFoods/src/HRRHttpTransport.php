<?php
/*******************************************************************************

    HRR Foods Plugin — HTTP Transport Interface

    Pluggable HTTP layer. Default impl is CurlHRRHttpTransport. Tests inject
    RecordingHRRHttpTransport. Dry-run mode injects FileWritingHRRHttpTransport.

    Every transport must return ['status' => int, 'headers' => array, 'body' => string].
    Status code 0 is reserved for transport-level failures (network, timeout, DNS).

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

interface HRRHttpTransport
{
    /**
     * Send a request.
     *
     * @param string $method GET, POST, PUT, DELETE
     * @param string $url full URL (transport must not further resolve)
     * @param array<string,string> $headers
     * @param string|null $body raw request body (usually JSON), or null for GET
     * @param int $timeout seconds
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function request($method, $url, array $headers, $body, $timeout);
}
