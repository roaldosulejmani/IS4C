<?php
/*******************************************************************************

    HRR Foods Plugin — Cron Task: Send POs to HRR

    Runs every 5 minutes. Finds POs where:
        vendorID = HRRVendorID
        placed = 1
        vendorOrderID IS NULL
    (i.e. the buyer already clicked "Send PO" but the HTTP POST hasn't
    succeeded yet), and submits them to HRR's POST /purchase-orders.

    On success, writes HRR's vendorOrderID back to PurchaseOrder.
    On retriable failure, leaves the row alone for the next run.
    On permanent failure (4xx other than 401/408/429), logs and surfaces
    in the admin page's "stuck POs" list.

    Schedule: every 5 minutes.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieTask')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FannieTask.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRSettings')) {
    include_once(dirname(__FILE__) . '/../src/HRRSettings.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRApiClient')) {
    include_once(dirname(__FILE__) . '/../src/HRRApiClient.php');
}
if (!class_exists('HRRFoodsExport')) {
    include_once(dirname(__FILE__) . '/../../../purchasing/exporters/HRRFoodsExport.php');
}

class HRRSendPurchaseOrderTask extends FannieTask
{
    public $name = 'HRR Foods: Send Purchase Orders';
    public $description = 'Submits placed=1 POs to HRR Foods and stamps the returned vendorOrderID.';
    public $default_schedule = array('min' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*');
    public $log_start_stop = true;

    public function run()
    {
        $settings = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::load();
        $vendorId = (int)$settings['HRRVendorID'];
        if ($vendorId <= 0) {
            $this->cronMsg('HRR Foods: HRRVendorID is not configured. Skipping.', FannieLogger::WARNING);
            return;
        }

        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));
        $p = $dbc->prepare('SELECT orderID, storeID, creationDate, placedDate, vendorOrderID
                            FROM PurchaseOrder
                            WHERE vendorID=? AND placed=1 AND (vendorOrderID IS NULL OR vendorOrderID=\'\')
                            ORDER BY orderID ASC
                            LIMIT 50');
        $rows = $dbc->getAllRows($p, array($vendorId));
        if (empty($rows)) {
            $this->cronMsg('HRR Foods: no queued POs to send.');
            return;
        }

        $logger = function ($level, $msg) { $this->cronMsg($msg); };
        $transport = $this->buildTransport($settings);
        $auth = new \COREPOS\Fannie\Plugin\HRRFoods\HRRAuth(
            $settings,
            $transport,
            \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::tokenCacheFile()
        );
        $storeMap = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
        $storeMap->load($settings);
        $client = new \COREPOS\Fannie\Plugin\HRRFoods\HRRApiClient($settings, $transport, $auth, $storeMap, $logger);

        $successCount = 0;
        $failCount = 0;
        foreach ($rows as $row) {
            $poId = (int)$row['orderID'];
            try {
                $exporter = new HRRFoodsExport();
                $json = $exporter->exportString($poId);
                $body = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::decodeOr($json, array());
                if (empty($body)) {
                    $this->cronMsg("HRR Foods: PO $poId has no body to send. Skipping.", FannieLogger::WARNING);
                    continue;
                }
                $resp = $client->submitPurchaseOrder($body);
                $vendorOrderId = isset($resp['vendorOrderID']) ? (string)$resp['vendorOrderID'] : '';
                if ($vendorOrderId === '') {
                    throw new \COREPOS\Fannie\Plugin\HRRFoods\HRRClientException(
                        'HRR response did not include vendorOrderID: ' . substr(json_encode($resp), 0, 200)
                    );
                }
                $upP = $dbc->prepare('UPDATE PurchaseOrder SET vendorOrderID=? WHERE orderID=?');
                $dbc->execute($upP, array(substr($vendorOrderId, 0, 25), $poId));
                $successCount++;
                $this->cronMsg("HRR Foods: PO $poId sent, vendorOrderID=$vendorOrderId");
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRRateLimitedException $e) {
                $this->cronMsg("HRR Foods: PO $poId rate-limited, will retry next run.", FannieLogger::INFO);
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRClientException $e) {
                $failCount++;
                $this->cronMsg("HRR Foods: PO $poId permanently failed: " . $e->getMessage(), FannieLogger::ERROR);
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRApiClientException $e) {
                // retriable transport failure - leave row alone
                $this->cronMsg("HRR Foods: PO $poId transport failure (will retry): " . $e->getMessage(), FannieLogger::WARNING);
            } catch (\Exception $e) {
                $failCount++;
                $this->cronMsg("HRR Foods: PO $poId unexpected error: " . $e->getMessage(), FannieLogger::ERROR);
            }
        }
        $this->cronMsg("HRR Foods: sent $successCount PO(s), $failCount failed.");
    }

    /**
     * @param array<string,string> $settings
     * @return \COREPOS\Fannie\Plugin\HRRFoods\HRRHttpTransport
     */
    private function buildTransport(array $settings)
    {
        if (!empty($settings['HRRDryRun']) && $settings['HRRDryRun'] !== '0') {
            $dir = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::pluginDir() . '/noauto/dryrun';
            return new \COREPOS\Fannie\Plugin\HRRFoods\FileWritingHRRHttpTransport($dir);
        }
        return new \COREPOS\Fannie\Plugin\HRRFoods\CurlHRRHttpTransport(true);
    }
}
