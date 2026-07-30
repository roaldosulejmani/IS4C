<?php
/*******************************************************************************

    HRR Foods Plugin — Dry-Run Transport

    When HRRDryRun=1, every outbound request is written to a JSON file under
    noauto/dryrun/ and a synthetic 202 Accepted response is returned. This
    lets the admin hand the resulting files to HRR support as a "this is
    what we'd send you" example before flipping HRRDryRun=0.

    Files written:
        {timestamp}-{METHOD}-{path-slug}.json
    Each file contains:
        { "request": {method, url, headers, body, timestamp},
          "response": {status: 202, body: {dryRun: true, savedTo: "..."} } }

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class FileWritingHRRHttpTransport implements HRRHttpTransport
{
    /** @var string */
    private $dir;

    public function __construct($dir)
    {
        $this->dir = rtrim((string)$dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }
    }

    public function request($method, $url, array $headers, $body, $timeout)
    {
        $method = strtoupper((string)$method);
        $parsed = parse_url((string)$url);
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $pathSlug = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($path, '/'));
        if ($pathSlug === '') {
            $pathSlug = 'root';
        }
        $ts = date('Ymd-His-u');
        $filename = sprintf('%s-%s-%s.json', $ts, $method, substr($pathSlug, 0, 80));
        $fullPath = $this->dir . DIRECTORY_SEPARATOR . $filename;

        $record = array(
            'request' => array(
                'method' => $method,
                'url' => (string)$url,
                'headers' => $headers,
                'body' => $body,
                'timeout' => (int)$timeout,
                'timestamp' => date('c'),
            ),
        );

        @file_put_contents($fullPath, HRRJson::encode($record));

        return array(
            'status' => 202,
            'headers' => array('content-type' => 'application/json'),
            'body' => HRRJson::encode(array(
                'dryRun' => true,
                'accepted' => false,
                'savedTo' => $filename,
            )),
        );
    }
}
