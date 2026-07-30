<?php
/*******************************************************************************

    HRR Foods Plugin — Cron Task: Pull Catalog + Prices

    Runs weekly. Walks the paginated HRR catalog, UPSERTs each item into
    vendorItems (PK = (sku, vendorID)), and optionally updates products.cost
    per HRRPriceUpdateMode. Cursor is stored at noauto/.catalog-cursor.json.

    Schedule: weekly, Sunday 03:00.

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

class HRRPullCatalogTask extends FannieTask
{
    public $name = 'HRR Foods: Pull Catalog';
    public $description = 'Walks the HRR catalog and updates vendorItems (and optionally products.cost).';
    public $default_schedule = array('min' => '0', 'hour' => '3', 'day' => '*', 'month' => '*', 'weekday' => '0');
    public $log_start_stop = true;

    public function run()
    {
        $settings = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::load();
        $vendorId = (int)$settings['HRRVendorID'];
        if ($vendorId <= 0) {
            $this->cronMsg('HRR Foods: HRRVendorID is not configured. Skipping.', FannieLogger::WARNING);
            return;
        }
        if (isset($settings['HRRPriceUpdateMode']) && $settings['HRRPriceUpdateMode'] === 'off') {
            $this->cronMsg('HRR Foods: HRRPriceUpdateMode=off. Skipping catalog pull.', FannieLogger::INFO);
            return;
        }

        $since = $this->readCursor();
        $batchSize = isset($settings['HRRBatchSize']) ? max(1, (int)$settings['HRRBatchSize']) : 500;

        $storeMap = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
        $storeMap->load($settings);
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
        $deptSelP = $dbc->prepare('SELECT deptID FROM vendorDepartments WHERE deptID=? AND vendorID=?');
        $deptInsP = $dbc->prepare('INSERT INTO vendorDepartments (vendorID, deptID, name, margin, testing, posDeptID)
            VALUES (?, ?, ?, 0, 0, 0)');

        $page = 1;
        $totalUpserted = 0;
        $latestSince = $since;
        while (true) {
            $result = $client->fetchCatalogPage($since, $page, $batchSize, null);
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
                if ($row['vendorDept'] > 0 && !$dbc->getValue($deptSelP, array($row['vendorDept'], $vendorId))) {
                    $dbc->execute($deptInsP, array($vendorId, $row['vendorDept'], (string)$row['vendorDept']));
                }
                if (isset($product['updatedAt']) && is_string($product['updatedAt']) && $product['updatedAt'] !== '') {
                    if ($latestSince === $since || strcmp($product['updatedAt'], $latestSince) > 0) {
                        $latestSince = $product['updatedAt'];
                    }
                }
                $totalUpserted++;
            }
            $dbc->commitTransaction();
            $this->writeCursor($latestSince);
            if ($result['nextPage'] === null || $result['nextPage'] <= $page) {
                break;
            }
            $page = (int)$result['nextPage'];
        }
        $this->cronMsg("HRR Foods: catalog pull complete, upserted $totalUpserted item(s).");
    }

    /**
     * @return string ISO timestamp
     */
    private function readCursor()
    {
        $file = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::pluginDir() . '/noauto/.catalog-cursor.json';
        if (!is_file($file)) {
            return '1970-01-01T00:00:00Z';
        }
        $raw = @file_get_contents($file);
        $j = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::decodeOr($raw, array());
        return isset($j['since']) ? (string)$j['since'] : '1970-01-01T00:00:00Z';
    }

    /**
     * @param string $since
     * @return void
     */
    private function writeCursor($since)
    {
        $file = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::pluginDir() . '/noauto/.catalog-cursor.json';
        @file_put_contents($file, \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::encode(array('since' => $since, 'updatedAt' => date('c'))));
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
