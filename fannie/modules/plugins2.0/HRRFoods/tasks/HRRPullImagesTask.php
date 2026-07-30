<?php
/*******************************************************************************

    HRR Foods Plugin — Cron Task: Pull Product Images

    For each vendorItems row with a UPC and no existing productUser.photo,
    fetch HRR's image URL and write it to productUser.photo. If
    HRRImageCacheEnabled=1, also download the binary to noauto/images/.

    Schedule: weekly, Monday 03:00.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieTask')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FannieTask.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRSettings')) {
    include_once(dirname(__FILE__) . '/../src/HRRSettings.php');
}

class HRRPullImagesTask extends FannieTask
{
    public $name = 'HRR Foods: Pull Product Images';
    public $description = 'Fetches HRR product image URLs and writes them to productUser.photo.';
    public $default_schedule = array('min' => '0', 'hour' => '3', 'day' => '*', 'month' => '*', 'weekday' => '1');
    public $log_start_stop = true;

    public function run()
    {
        $settings = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::load();
        $vendorId = (int)$settings['HRRVendorID'];
        if ($vendorId <= 0) {
            $this->cronMsg('HRR Foods: HRRVendorID is not configured. Skipping.', FannieLogger::WARNING);
            return;
        }
        $cacheEnabled = !empty($settings['HRRImageCacheEnabled']) && $settings['HRRImageCacheEnabled'] !== '0';

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

        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));
        $p = $dbc->prepare('SELECT vi.upc, vi.sku
                            FROM vendorItems AS vi
                            LEFT JOIN productUser AS pu ON pu.upc=vi.upc
                            WHERE vi.vendorID=? AND vi.upc IS NOT NULL AND vi.upc<>\'\'
                              AND (pu.upc IS NULL OR pu.photo IS NULL OR pu.photo=\'\')
                            LIMIT 200');
        $rows = $dbc->getAllRows($p, array($vendorId));
        $total = 0;
        foreach ($rows as $row) {
            $upc = (string)$row['upc'];
            try {
                $img = $client->fetchImage($upc);
                if (!isset($img['url']) || !is_string($img['url']) || $img['url'] === '') {
                    continue;
                }
                $url = (string)$img['url'];
                if (strlen($url) > 255) {
                    $this->cronMsg("HRR Foods: image URL for UPC $upc exceeds 255 chars; skipping.", FannieLogger::WARNING);
                    continue;
                }
                $this->upsertProductUser($dbc, $upc, $url);
                if ($cacheEnabled) {
                    $this->cacheImage($upc, $url);
                }
                $total++;
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRApiClientException $e) {
                $this->cronMsg("HRR Foods: image fetch for UPC $upc failed: " . $e->getMessage(), FannieLogger::WARNING);
            }
        }
        $this->cronMsg("HRR Foods: refreshed $total image URL(s).");
    }

    /**
     * @param SQLManager $dbc
     * @param string $upc
     * @param string $url
     * @return void
     */
    private function upsertProductUser($dbc, $upc, $url)
    {
        $selP = $dbc->prepare('SELECT upc FROM productUser WHERE upc=?');
        $exists = $dbc->getValue($selP, array($upc));
        if ($exists) {
            $upP = $dbc->prepare('UPDATE productUser SET photo=? WHERE upc=?');
            $dbc->execute($upP, array($url, $upc));
        } else {
            $insP = $dbc->prepare('INSERT INTO productUser (upc, photo) VALUES (?, ?)');
            $dbc->execute($insP, array($upc, $url));
        }
    }

    /**
     * @param string $upc
     * @param string $url
     * @return void
     */
    private function cacheImage($upc, $url)
    {
        $dir = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::pluginDir() . '/noauto/images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $data !== false) {
            @file_put_contents($dir . '/' . $upc . '.jpg', $data);
        }
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
