<?php
/*******************************************************************************

    HRR Foods Plugin — One-Shot Task: Bootstrap Catalog

    Used for the very first install. Does NOT respect the catalog cursor
    (always pulls from '1970-01-01T00:00:00Z'), and iterates every account
    code in HRRStoreMap to pull per-DC pricing. Idempotent like
    HRRPullCatalogTask.

    CLI: php FannieTask.php HRRBootstrapTask

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieTask')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FannieTask.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRSettings')) {
    include_once(dirname(__FILE__) . '/../src/HRRSettings.php');
}

class HRRBootstrapTask extends FannieTask
{
    public $name = 'HRR Foods: Bootstrap (one-shot)';
    public $description = 'Full initial catalog pull across every HRR account code. Use once on first install.';
    public $log_start_stop = true;

    public function run()
    {
        $settings = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::load();
        $vendorId = (int)$settings['HRRVendorID'];
        if ($vendorId <= 0) {
            $this->cronMsg('HRR Foods: HRRVendorID is not configured. Skipping.', FannieLogger::WARNING);
            return;
        }

        $storeMap = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
        $storeMap->load($settings);
        $accounts = array_values(array_unique(array_filter(array_map('strval', $storeMap->all()))));
        if (empty($accounts)) {
            $accounts = array(null);
        }
        $this->cronMsg('HRR Foods: bootstrap will walk ' . count($accounts) . ' account(s).');

        $logger = function ($level, $msg) { $this->cronMsg($msg); };
        $transport = $this->buildTransport($settings);
        $auth = new \COREPOS\Fannie\Plugin\HRRFoods\HRRAuth(
            $settings,
            $transport,
            \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::tokenCacheFile()
        );
        $client = new \COREPOS\Fannie\Plugin\HRRFoods\HRRApiClient($settings, $transport, $auth, $storeMap, $logger);
        $normalizer = new \COREPOS\Fannie\Plugin\HRRFoods\HRRCatalogNormalizer();

        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));
        $selP = $dbc->prepare('SELECT sku FROM vendorItems WHERE sku=? AND vendorID=?');
        $insP = $dbc->prepare('INSERT INTO vendorItems
            (upc, sku, brand, description, size, units, cost, saleCost, vendorDept, vendorID, srp, modified)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . $dbc->now() . ')');
        $upP = $dbc->prepare('UPDATE vendorItems SET upc=?, brand=?, description=?, size=?, units=?, cost=?, saleCost=?, vendorDept=?, srp=?, modified=' . $dbc->now() . '
            WHERE sku=? AND vendorID=?');

        $batchSize = isset($settings['HRRBatchSize']) ? max(1, (int)$settings['HRRBatchSize']) : 500;
        $grandTotal = 0;

        foreach ($accounts as $accountCode) {
            $page = 1;
            $accountTotal = 0;
            while (true) {
                $result = $client->fetchCatalogPage('1970-01-01T00:00:00Z', $page, $batchSize, $accountCode);
                $items = $result['items'];
                if (empty($items)) {
                    break;
                }
                $dbc->startTransaction();
                foreach ($items as $product) {
                    $r = $normalizer->toVendorItemRow($product, $vendorId);
                    $row = $r['row'];
                    if (empty($row['sku'])) {
                        continue;
                    }
                    $exists = $dbc->getValue($selP, array($row['sku'], $vendorId));
                    if ($exists) {
                        $dbc->execute($upP, array(
                            $row['upc'], $row['brand'], $row['description'], $row['size'], $row['units'],
                            $row['cost'], $row['saleCost'], $row['vendorDept'], $row['srp'],
                            $row['sku'], $vendorId,
                        ));
                    } else {
                        $dbc->execute($insP, array(
                            $row['upc'], $row['sku'], $row['brand'], $row['description'], $row['size'], $row['units'],
                            $row['cost'], $row['saleCost'], $row['vendorDept'], $vendorId, $row['srp'],
                        ));
                    }
                    $accountTotal++;
                }
                $dbc->commitTransaction();
                if ($result['nextPage'] === null || $result['nextPage'] <= $page) {
                    break;
                }
                $page = (int)$result['nextPage'];
            }
            $this->cronMsg("HRR Foods: account $accountCode pulled $accountTotal item(s).");
            $grandTotal += $accountTotal;
        }
        $this->cronMsg("HRR Foods: bootstrap complete, total $grandTotal item(s) across " . count($accounts) . ' account(s).');
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
