<?php
/*******************************************************************************

    HRR Foods Plugin — Test Transport

    Records every request to an in-memory stack and returns canned responses
    from a queue. PHPUnit tests push responses via enqueue() and then assert
    on getRequests().

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class RecordingHRRHttpTransport implements HRRHttpTransport
{
    /** @var array<int,array> */
    private $requests = array();

    /** @var array<int,array> */
    private $responses = array();

    /**
     * Queue a canned response. Subsequent requests consume the queue in order.
     *
     * @param int $status
     * @param array<string,string> $headers
     * @param string $body
     * @return void
     */
    public function enqueue($status, array $headers = array(), $body = '')
    {
        $this->responses[] = array(
            'status' => (int)$status,
            'headers' => $headers,
            'body' => (string)$body,
        );
    }

    /**
     * @return array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:int}>
     */
    public function getRequests()
    {
        return $this->requests;
    }

    public function request($method, $url, array $headers, $body, $timeout)
    {
        $this->requests[] = array(
            'method' => strtoupper((string)$method),
            'url' => (string)$url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => (int)$timeout,
        );
        if (empty($this->responses)) {
            // No canned response queued — return a deterministic 500 so tests fail loudly.
            return array(
                'status' => 500,
                'headers' => array('content-type' => 'application/json'),
                'body' => '{"error":"no_canned_response_queued"}',
            );
        }
        return array_shift($this->responses);
    }
}
