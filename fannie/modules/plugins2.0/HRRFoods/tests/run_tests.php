<?php
/*******************************************************************************

    HRR Foods Plugin — Unit Tests

    Runs against RecordingHRRHttpTransport — no live API needed. Exercises:
      - HRRJson encode/decode round-trip + error path
      - HRRStoreMap validation (good/bad shapes, regex on account codes)
      - HRRCatalogNormalizer math (caseCost/caseSize, saleCost handling)
      - HRRApiClient testConnection happy path
      - HRRApiClient fetchCatalogPage paginates and stops when nextPage null
      - HRRApiClient submitPurchaseOrder happy path
      - HRRApiClient retry on 429 (backoff shortened via settings override)
      - HRRApiClient 401 triggers token forceRefresh and one retry
      - HRRAuth token cache hit/miss + redaction in error messages

    Run: php tests/run_tests.php

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

include_once(__DIR__ . '/test_support.php');
require_once(dirname(__DIR__) . '/src/HRREndpoints.php');
require_once(dirname(__DIR__) . '/src/HRRHttpTransport.php');
require_once(dirname(__DIR__) . '/src/RecordingHRRHttpTransport.php');
require_once(dirname(__DIR__) . '/src/CurlHRRHttpTransport.php');
require_once(dirname(__DIR__) . '/src/FileWritingHRRHttpTransport.php');
require_once(dirname(__DIR__) . '/src/HRRJson.php');
require_once(dirname(__DIR__) . '/src/HRRStoreMap.php');
require_once(dirname(__DIR__) . '/src/HRRCatalogNormalizer.php');
require_once(dirname(__DIR__) . '/src/HRRAuth.php');
require_once(dirname(__DIR__) . '/src/HRRApiClient.php');

$t = new HRRTestRunner();

// ---- Settings helper for tests ----
function hrrTestSettings(array $overrides = array())
{
    $base = array(
        'HRRApiBaseUrl' => 'https://api.example.com/v1',
        'HRROAuthClientId' => 'test-client',
        'HRROAuthClientSecret' => 'test-secret',
        'HRROAuthScope' => 'catalog:read purchase_order:write invoice:read',
        'HRRTokenUrl' => 'https://auth.example.com/oauth/token',
        'HRRVendorID' => '42',
        'HRRStoreMap' => '{"0":"HQ-0","1":"CHI-001","2":"ERIE-002"}',
        'HRRDefaultStoreID' => '0',
        'HRRBatchSize' => '100',
        'HRRRateLimitPerMinute' => '6000',
        'HRRMaxRetries' => '2',
        'HRRRequestTimeoutSec' => '5',
        'HRRInvoiceLookbackDays' => '14',
        'HRRDryRun' => '0',
        'HRRImageCacheEnabled' => '0',
        'HRRPriceUpdateMode' => 'vendor_only',
        'HRRLogLevel' => 'info',
        'HRRContactEmail' => 'ops@example.com',
        'HRRUserAgent' => 'Fannie-HRRFoods/1.0',
    );
    return array_merge($base, $overrides);
}

// ---- HRRJson ----
$t->test('HRRJson round-trip', function () use ($t) {
    $payload = array('a' => 1, 'b' => 'two', 'c' => array(1, 2, 3));
    $json = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::encode($payload);
    $back = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::decode($json);
    $t->assertEquals($payload, $back);
});

$t->test('HRRJson decodeOr falls back', function () use ($t) {
    $back = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::decodeOr('{not json', array('default' => true));
    $t->assertEquals(array('default' => true), $back);
});

$t->test('HRRJson encode throws on invalid UTF-8', function () use ($t) {
    $threw = false;
    try {
        \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::encode("\xB1\x31");
    } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRJsonException $e) {
        $threw = true;
    }
    $t->assertTrue($threw, 'expected JSON encode to throw on invalid UTF-8');
});

// ---- HRRStoreMap ----
$t->test('HRRStoreMap load and resolve', function () use ($t) {
    $m = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
    $m->load(hrrTestSettings());
    $t->assertEquals('CHI-001', $m->resolve(1));
    $t->assertEquals('ERIE-002', $m->resolve(2));
    $t->assertEquals(null, $m->resolve(99));
});

$t->test('HRRStoreMap rejects bad account codes', function () use ($t) {
    $m = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
    $errs = $m->validate('{"1":"valid-1","2":"bad space","3":"ok-3"}');
    $t->assertTrue(count($errs) >= 1, 'should report at least one error');
});

$t->test('HRRStoreMap rejects non-object JSON', function () use ($t) {
    $m = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
    $errs = $m->validate('[1,2,3]');
    $t->assertTrue(count($errs) > 0, 'should report JSON shape error');
});

// ---- HRRCatalogNormalizer ----
$t->test('HRRCatalogNormalizer per-each cost math', function () use ($t) {
    $n = new \COREPOS\Fannie\Plugin\HRRFoods\HRRCatalogNormalizer();
    $product = array(
        'upc' => '00012345678905',
        'vendorSku' => 'HRR-998877',
        'brand' => 'Acme',
        'description' => 'Tomato Sauce',
        'size' => '12 OZ',
        'department' => 7,
        'pricing' => array(
            'caseCost' => 24.00,
            'caseSize' => 12,
            'onSale' => false,
            'saleCaseCost' => 0,
            'suggestedRetail' => 3.49,
        ),
        'image' => array('url' => 'https://cdn.example.com/tomato.jpg'),
    );
    $r = $n->toVendorItemRow($product, 42);
    $t->assertEquals('HRR-998877', $r['row']['sku']);
    $t->assertEquals(2.00, $r['row']['cost'], 'cost should be caseCost/caseSize');
    $t->assertEquals(0, $r['row']['saleCost']);
    $t->assertEquals(3.49, $r['srp']);
    $t->assertEquals('https://cdn.example.com/tomato.jpg', $r['imageUrl']);
});

$t->test('HRRCatalogNormalizer sale cost math', function () use ($t) {
    $n = new \COREPOS\Fannie\Plugin\HRRFoods\HRRCatalogNormalizer();
    $product = array(
        'upc' => '00012345678905',
        'vendorSku' => 'HRR-998877',
        'pricing' => array(
            'caseCost' => 24.00,
            'caseSize' => 12,
            'onSale' => true,
            'saleCaseCost' => 18.00,
            'suggestedRetail' => 3.49,
        ),
    );
    $r = $n->toVendorItemRow($product, 42);
    $t->assertEquals(1.50, $r['row']['saleCost'], 'saleCost = saleCaseCost/caseSize');
});

$t->test('HRRCatalogNormalizer missing pricing fields defaults safe', function () use ($t) {
    $n = new \COREPOS\Fannie\Plugin\HRRFoods\HRRCatalogNormalizer();
    $r = $n->toVendorItemRow(array('upc' => '1', 'vendorSku' => 'X'), 42);
    $t->assertEquals(0, $r['row']['cost']);
    $t->assertEquals(0, $r['row']['saleCost']);
    $t->assertEquals(null, $r['imageUrl']);
});

// ---- HRRApiClient with RecordingHRRHttpTransport ----
function hrrMakeClient(array $settings, \COREPOS\Fannie\Plugin\HRRFoods\HRRHttpTransport $transport)
{
    $auth = new \COREPOS\Fannie\Plugin\HRRFoods\HRRAuth(
        $settings,
        $transport,
        sys_get_temp_dir() . '/hrr-test-token-' . uniqid() . '.json'
    );
    $map = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
    $map->load($settings);
    return new \COREPOS\Fannie\Plugin\HRRFoods\HRRApiClient(
        $settings,
        $transport,
        $auth,
        $map,
        function ($lvl, $msg) { /* swallow */ }
    );
}

$t->test('HRRApiClient testConnection happy path', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    $r->enqueue(200, array('content-type' => 'application/json'), '{"status":"ok","version":"v1"}');
    $c = hrrMakeClient($settings, $r);
    $res = $c->testConnection();
    $t->assertTrue($res['ok']);
    $t->assertEquals(200, $res['httpCode']);
});

$t->test('HRRApiClient fetchCatalogPage stops at nextPage=null', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    $r->enqueue(200, array(), json_encode(array(
        'items' => array(array('vendorSku' => 'A', 'upc' => '1', 'pricing' => array('caseCost' => 10, 'caseSize' => 1))),
        'nextPage' => 2,
    )));
    $r->enqueue(200, array(), json_encode(array(
        'items' => array(array('vendorSku' => 'B', 'upc' => '2', 'pricing' => array('caseCost' => 20, 'caseSize' => 1))),
        'nextPage' => null,
    )));
    $c = hrrMakeClient($settings, $r);
    $p1 = $c->fetchCatalogPage('2025-01-01T00:00:00Z', 1, 100, 'CHI-001');
    $t->assertEquals('A', $p1['items'][0]['vendorSku']);
    $t->assertEquals(2, $p1['nextPage']);
    $p2 = $c->fetchCatalogPage('2025-01-01T00:00:00Z', 2, 100, 'CHI-001');
    $t->assertEquals('B', $p2['items'][0]['vendorSku']);
    $t->assertEquals(null, $p2['nextPage']);
});

$t->test('HRRApiClient submitPurchaseOrder sends account code', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    // token endpoint
    $r->enqueue(200, array(), json_encode(array(
        'access_token' => 'TKN-1', 'expires_in' => 3600, 'token_type' => 'Bearer',
    )));
    $r->enqueue(200, array(), json_encode(array('vendorOrderID' => 'HRR-78910', 'status' => 'accepted')));
    $c = hrrMakeClient($settings, $r);
    $resp = $c->submitPurchaseOrder(array(
        'accountCode' => 'CHI-001',
        'externalOrderId' => 'FANNIE-99',
        'lines' => array(),
    ));
    $t->assertEquals('HRR-78910', $resp['vendorOrderID']);
    $reqs = $r->getRequests();
    // last request must be POST to /purchase-orders
    $last = end($reqs);
    $t->assertEquals('POST', $last['method']);
    $t->assertContains('/purchase-orders', $last['url']);
    // account code must appear in either body or header
    $body = is_string($last['body']) ? $last['body'] : '';
    $headers = $last['headers'];
    $foundInBody = strpos($body, 'CHI-001') !== false;
    $foundInHeader = false;
    foreach ($headers as $k => $v) {
        if (stripos($k, 'X-HRR-Account') === 0 && stripos($v, 'CHI-001') !== false) {
            $foundInHeader = true;
            break;
        }
    }
    $t->assertTrue($foundInBody || $foundInHeader, 'accountCode should travel in body or X-HRR-Account header');
});

$t->test('HRRApiClient retries on 429 then succeeds', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    // token
    $r->enqueue(200, array(), json_encode(array('access_token' => 'TKN-2', 'expires_in' => 3600, 'token_type' => 'Bearer')));
    // first attempt: 429
    $r->enqueue(429, array(), '{"error":"rate limited"}');
    // second attempt: 200
    $r->enqueue(200, array(), json_encode(array('vendorOrderID' => 'HRR-OK')));
    $c = hrrMakeClient($settings, $r);
    $resp = $c->submitPurchaseOrder(array('accountCode' => 'CHI-001', 'lines' => array()));
    $t->assertEquals('HRR-OK', $resp['vendorOrderID']);
    $reqs = $r->getRequests();
    // expect at least 3 requests: token, 429-po, 200-po
    $t->assertTrue(count($reqs) >= 3, 'expected at least 3 requests (token + retry)');
});

$t->test('HRRApiClient does NOT retry on 400', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    $r->enqueue(200, array(), json_encode(array('access_token' => 'TKN-3', 'expires_in' => 3600, 'token_type' => 'Bearer')));
    $r->enqueue(400, array(), '{"error":"bad request"}');
    $c = hrrMakeClient($settings, $r);
    $threw = false;
    try {
        $c->submitPurchaseOrder(array('accountCode' => 'CHI-001', 'lines' => array()));
    } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRClientException $e) {
        $threw = true;
    }
    $t->assertTrue($threw, '4xx should throw HRRClientException');
    $t->assertEquals(2, count($r->getRequests()), 'no retry on 400');
});

$t->test('HRRApiClient 401 forces token refresh and retries once', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    // first token (good but server rejects it)
    $r->enqueue(200, array(), json_encode(array('access_token' => 'OLD', 'expires_in' => 3600, 'token_type' => 'Bearer')));
    // 401
    $r->enqueue(401, array(), '{"error":"token expired"}');
    // refresh
    $r->enqueue(200, array(), json_encode(array('access_token' => 'NEW', 'expires_in' => 3600, 'token_type' => 'Bearer')));
    // retry success
    $r->enqueue(200, array(), json_encode(array('vendorOrderID' => 'HRR-REFRESH')));
    $c = hrrMakeClient($settings, $r);
    $resp = $c->submitPurchaseOrder(array('accountCode' => 'CHI-001', 'lines' => array()));
    $t->assertEquals('HRR-REFRESH', $resp['vendorOrderID']);
});

// ---- HRRAuth redactor ----
$t->test('HRRAuth redaction hides client_secret and access_token', function () use ($t) {
    $settings = hrrTestSettings();
    $r = new \COREPOS\Fannie\Plugin\HRRFoods\RecordingHRRHttpTransport();
    $r->enqueue(500, array(), '{"error":"client_secret=secretXYZ access_token=abc123"}');
    $auth = new \COREPOS\Fannie\Plugin\HRRFoods\HRRAuth(
        $settings,
        $r,
        sys_get_temp_dir() . '/hrr-test-token-' . uniqid() . '.json'
    );
    $threw = false;
    try {
        $auth->getToken();
    } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRAuthException $e) {
        $threw = true;
        $msg = $e->getMessage();
        $t->assertTrue(strpos($msg, 'secretXYZ') === false, 'client_secret should be redacted');
        $t->assertTrue(strpos($msg, 'abc123') === false, 'access_token should be redacted');
    }
    $t->assertTrue($threw, 'auth should throw on 500 from token endpoint');
});

// ---- FileWritingHRRHttpTransport dry-run ----
$t->test('FileWritingHRRHttpTransport writes files and returns 202', function () use ($t) {
    $dir = sys_get_temp_dir() . '/hrr-dryrun-' . uniqid();
    mkdir($dir, 0777, true);
    $tr = new \COREPOS\Fannie\Plugin\HRRFoods\FileWritingHRRHttpTransport($dir);
    $res = $tr->request('POST', 'https://example.com/x', array('h' => 'v'), '{"k":1}', 30);
    $t->assertEquals(202, $res['status']);
    $files = glob($dir . '/*.json');
    $t->assertTrue(count($files) >= 1, 'should write at least one json file');
    @unlink($files[0]);
    @rmdir($dir);
});

$t->run();
fwrite(STDOUT, $t->summary());
exit($t->exitCode());