<?php
/*******************************************************************************

    HRR Foods Plugin — Cron Task: Pull Invoices

    Runs nightly. Walks every account code in HRRStoreMap, fetches closed
    invoices for the lookback window, and creates a PurchaseOrder + items
    for any not already imported. Idempotent on (vendorID, vendorInvoiceID).

    Schedule: nightly 02:00.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieTask')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FannieTask.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRSettings')) {
    include_once(dirname(__FILE__) . '/../src/HRRSettings.php');
}
if (!class_exists('HRRInvoiceImport')) {
    include_once(dirname(__FILE__) . '/../../../purchasing/importers/HRRInvoiceImport.php');
}

class HRRPullInvoicesTask extends FannieTask
{
    public $name = 'HRR Foods: Pull Invoices';
    public $description = 'Pulls closed invoices from HRR and creates PurchaseOrder + items.';
    public $default_schedule = array('min' => '0', 'hour' => '2', 'day' => '*', 'month' => '*', 'weekday' => '*');
    public $log_start_stop = true;

    public function run()
    {
        $settings = \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::load();
        $vendorId = (int)$settings['HRRVendorID'];
        if ($vendorId <= 0) {
            $this->cronMsg('HRR Foods: HRRVendorID is not configured. Skipping.', FannieLogger::WARNING);
            return;
        }

        $lookback = isset($settings['HRRInvoiceLookbackDays']) ? (int)$settings['HRRInvoiceLookbackDays'] : 14;
        if ($lookback < 1) {
            $lookback = 14;
        }
        $since = date('c', time() - $lookback * 86400);

        $storeMap = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
        $storeMap->load($settings);
        $accountToStoreId = $this->invertMap($storeMap->all());

        $logger = function ($level, $msg) { $this->cronMsg($msg); };
        $transport = $this->buildTransport($settings);
        $auth = new \COREPOS\Fannie\Plugin\HRRFoods\HRRAuth(
            $settings,
            $transport,
            \COREPOS\Fannie\Plugin\HRRFoods\HRRSettings::tokenCacheFile()
        );
        $client = new \COREPOS\Fannie\Plugin\HRRFoods\HRRApiClient($settings, $transport, $auth, $storeMap, $logger);
        $importer = new HRRInvoiceImport();

        $totalImported = 0;
        $totalSkipped = 0;
        foreach ($accountToStoreId as $accountCode => $storeId) {
            try {
                $page = $client->fetchInvoices($since, $accountCode);
                foreach ($page['items'] as $invoiceSummary) {
                    if (!is_array($invoiceSummary) || !isset($invoiceSummary['invoiceId'])) {
                        continue;
                    }
                    $invoiceId = (string)$invoiceSummary['invoiceId'];
                    $detail = $client->fetchInvoice($invoiceId, $accountCode);
                    $poId = $importer->import($detail, $storeId, $vendorId);
                    if ($poId > 0) {
                        $totalImported++;
                        $this->cronMsg("HRR Foods: imported invoice $invoiceId as PO $poId (store=$storeId, account=$accountCode)");
                    } else {
                        $totalSkipped++;
                    }
                }
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRClientException $e) {
                $this->cronMsg("HRR Foods: invoice pull for $accountCode failed: " . $e->getMessage(), FannieLogger::ERROR);
            } catch (\COREPOS\Fannie\Plugin\HRRFoods\HRRApiClientException $e) {
                $this->cronMsg("HRR Foods: invoice pull for $accountCode transport failure: " . $e->getMessage(), FannieLogger::WARNING);
            }
        }
        $this->cronMsg("HRR Foods: imported $totalImported invoice(s), skipped $totalSkipped.");
    }

    /**
     * Invert [storeId => accountCode] to [accountCode => storeId] for iteration.
     *
     * @param array<int,string> $map
     * @return array<string,int>
     */
    private function invertMap(array $map)
    {
        $out = array();
        foreach ($map as $storeId => $accountCode) {
            if (!isset($out[$accountCode])) {
                $out[$accountCode] = (int)$storeId;
            }
        }
        return $out;
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
