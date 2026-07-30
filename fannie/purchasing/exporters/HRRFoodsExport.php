<?php
/*******************************************************************************

    HRR Foods Plugin — PO JSON Exporter

    Triggered by ViewPurchaseOrders.php via the URL:
        ?id={poID}&export=HRRFoodsExport

    (CORRECTION FROM INITIAL PLAN: we use the `export` query param, NOT
    `sendAs`. The `sendAs` path runs the email-and-place flow
    [get_id_sendAs_handler, line 264] which is wrong for HTTP transport.
    The `export` path [get_id_export_handler, line 217] is pure render. We
    hijack it to do the queue+stamp work ourselves.)

    Responsibilities of export_order($poId):
      - Build the JSON body for HRR's POST /purchase-orders.
      - Write the body to noauto/dryrun/po-{poId}-{ts}.json (paper trail).
      - Write the body to noauto/po-queue/{poId}.json (defense in depth
        input for HRRSendPurchaseOrderTask).
      - UPDATE PurchaseOrder SET placed=1, placedDate=NOW() WHERE orderID=?
        so the cron task has something to pick up.
      - Log the action.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieDB')) {
    include_once(dirname(__FILE__) . '/../../classlib2.0/FannieDB.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRStoreMap')) {
    include_once(dirname(__FILE__) . '/../modules/plugins2.0/HRRFoods/src/HRRStoreMap.php');
}
if (!class_exists('COREPOS\\Fannie\\Plugin\\HRRFoods\\HRRJson')) {
    include_once(dirname(__FILE__) . '/../modules/plugins2.0/HRRFoods/src/HRRJson.php');
}

class HRRFoodsExport
{
    public $nice_name = 'HRR Foods (JSON)';
    public $extension = 'json';
    public $mime_type = 'application/json';

    public function send_headers()
    {
        header('Content-type: application/json');
        header('Content-Disposition: attachment; filename=po_export.json');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Returns the JSON string for the PO. Does not write to disk.
     *
     * @param int $poId
     * @return string JSON
     */
    public function exportString($poId)
    {
        $body = $this->buildBody($poId);
        return \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::encode($body);
    }

    /**
     * The export_order() entry point called by get_id_export_handler().
     * Builds the JSON, writes it to disk, stamps the PO placed=1, and
     * outputs a small HTML confirmation.
     *
     * @param int $poId
     * @return void
     */
    public function export_order($poId)
    {
        $poId = (int)$poId;
        $body = $this->buildBody($poId);
        $json = \COREPOS\Fannie\Plugin\HRRFoods\HRRJson::encode($body);

        // Paper trail
        $dryrunDir = dirname(__FILE__) . '/../modules/plugins2.0/HRRFoods/noauto/dryrun';
        $queueDir  = dirname(__FILE__) . '/../modules/plugins2.0/HRRFoods/noauto/po-queue';
        $logsDir   = dirname(__FILE__) . '/../modules/plugins2.0/HRRFoods/noauto/logs';
        if (!is_dir($dryrunDir)) { @mkdir($dryrunDir, 0777, true); }
        if (!is_dir($queueDir))  { @mkdir($queueDir, 0777, true); }
        if (!is_dir($logsDir))   { @mkdir($logsDir, 0777, true); }

        $ts = date('Ymd-His');
        @file_put_contents($dryrunDir . '/po-' . $poId . '-' . $ts . '.json', $json);
        @file_put_contents($queueDir . '/' . $poId . '.json', $json);

        // Stamp placed=1
        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));
        $placeP = $dbc->prepare('UPDATE PurchaseOrder SET placed=1, placedDate=' . $dbc->now() . ' WHERE orderID=?');
        $dbc->execute($placeP, array($poId));

        // Log
        @file_put_contents(
            $logsDir . '/po-export.log',
            date('c') . " po={$poId} account={$body['accountCode']} lines=" . count($body['lines']) . " placed=1\n",
            FILE_APPEND
        );

        // Render
        $this->send_headers();
        echo $json;
    }

    /**
     * Build the JSON body that HRR's POST /purchase-orders expects.
     *
     * @param int $poId
     * @return array
     */
    private function buildBody($poId)
    {
        $poId = (int)$poId;
        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));

        $order = new PurchaseOrderModel($dbc);
        $order->orderID($poId);
        $order->load();

        $items = new PurchaseOrderItemsModel($dbc);
        $items->orderID($poId);
        $itemRows = $items->find();

        $storeId = (int)$order->storeID();
        $accountCode = $this->resolveAccountCode($storeId);

        $lines = array();
        foreach ($itemRows as $row) {
            $lines[] = array(
                'vendorSku' => (string)$row->sku(),
                'quantityCases' => (float)$row->quantity(),
                'unitCost' => (float)$row->unitCost(),
                'requestedUnitSize' => (string)$row->unitSize(),
            );
        }

        $body = array(
            'accountCode' => (string)$accountCode,
            'externalOrderId' => 'FANNIE-' . $poId,
            'orderDate' => $this->isoDate($order->creationDate()),
            'requestedDeliveryDate' => $this->isoDate($order->placedDate()),
            'specialInstructions' => '',
            'lines' => $lines,
        );
        return $body;
    }

    /**
     * Resolve the HRR account code for a FANNIE storeID using the
     * HRRStoreMap JSON value in PluginSettings. Falls back to "" when
     * unmapped; the cron task will log an error and skip in that case.
     *
     * @param int $storeId
     * @return string
     */
    private function resolveAccountCode($storeId)
    {
        try {
            $plugin = new \COREPOS\Fannie\Plugin\HRRFoods\HRRFoods();
            $settings = $plugin->getSettings();
            $map = new \COREPOS\Fannie\Plugin\HRRFoods\HRRStoreMap();
            $map->load($settings);
            $resolved = $map->resolve((int)$storeId);
            if ($resolved !== null) {
                return $resolved;
            }
            $defaultId = isset($settings['HRRDefaultStoreID']) ? (int)$settings['HRRDefaultStoreID'] : 0;
            $resolved = $map->resolve($defaultId);
            return $resolved !== null ? $resolved : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Normalize a datetime to ISO-8601. Returns null if input is empty.
     *
     * @param string|null $raw
     * @return string|null
     */
    private function isoDate($raw)
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('c', $ts);
    }
}
