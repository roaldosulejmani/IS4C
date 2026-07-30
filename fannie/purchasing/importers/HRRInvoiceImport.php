<?php
/*******************************************************************************

    HRR Foods Plugin — JSON Invoice Importer

    Standalone service class. NOT a subclass of ImportPurchaseOrder.php —
    that's a FannieUploadPage (spreadsheet UI) and not a service base.

    Surface: HRRInvoiceImport::import(array $invoiceJson, int $storeId, int $vendorId): int
      - Returns the new (or updated) PurchaseOrder.orderID
      - Idempotent on (vendorID, vendorInvoiceID): if a PO with that
        invoice number already exists for this vendor, updates its lines
        in place rather than creating a duplicate.

    Header fields (best guess for HRR's eventual API):
      invoiceNumber, purchaseOrderNumber, orderDate, lines: [...]

    Line fields:
      vendorSku, quantityCases, unitCostEach, caseSize, unitSize, brand,
      description, upc

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieDB')) {
    include_once(dirname(__FILE__) . '/../../classlib2.0/FannieDB.php');
}
if (!class_exists('BarcodeLib')) {
    include_once(dirname(__FILE__) . '/../../classlib2.0/lib/BarcodeLib.php');
}

class HRRInvoiceImport
{
    /**
     * Import a single HRR invoice document as a FANNIE PurchaseOrder + items.
     *
     * @param array $invoice JSON-decoded HRR invoice document
     * @param int $storeId FANNIE storeID (resolved from HRRStoreMap by the caller)
     * @param int $vendorId FANNIE vendorID (HRR's entry in the vendors table)
     * @return int the PurchaseOrder.orderID
     */
    public function import(array $invoice, $storeId, $vendorId)
    {
        $storeId = (int)$storeId;
        $vendorId = (int)$vendorId;

        $invoiceNumber = isset($invoice['invoiceNumber']) ? (string)$invoice['invoiceNumber'] : '';
        $purchaseOrderNumber = isset($invoice['purchaseOrderNumber']) ? (string)$invoice['purchaseOrderNumber'] : '';
        $orderDate = isset($invoice['orderDate']) ? (string)$invoice['orderDate'] : '';
        $lines = isset($invoice['lines']) && is_array($invoice['lines']) ? $invoice['lines'] : array();

        $dbc = FannieDB::get(FannieConfig::config('OP_DB'));

        // Idempotency: skip if we already have a PO for this invoice.
        $existingId = $this->findExistingPoId($dbc, $vendorId, $invoiceNumber);

        if ($existingId !== null) {
            // Update lines in place: delete existing items, re-insert.
            $delP = $dbc->prepare('DELETE FROM PurchaseOrderItems WHERE orderID=?');
            $dbc->execute($delP, array($existingId));
            $orderId = (int)$existingId;
        } else {
            // Create a new header.
            $order = new PurchaseOrderModel($dbc);
            $order->vendorID($vendorId);
            $order->storeID($storeId);
            $order->creationDate($this->normalizeDate($orderDate));
            $order->placed(1);
            $order->placedDate($this->normalizeDate($orderDate));
            $order->userID('HRR_AUTO');
            $order->vendorOrderID(substr($purchaseOrderNumber, 0, 25));
            $order->vendorInvoiceID(substr($invoiceNumber, 0, 25));
            $order->save();
            $orderId = (int)$order->orderID();
        }

        // Insert line items.
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $item = new PurchaseOrderItemsModel($dbc);
            $item->orderID($orderId);
            $item->sku(isset($line['vendorSku']) ? (string)$line['vendorSku'] : '');
            $item->quantity(isset($line['quantityCases']) ? (float)$line['quantityCases'] : 0);
            $item->unitCost(isset($line['unitCostEach']) ? (float)$line['unitCostEach'] : 0);
            $item->caseSize(isset($line['caseSize']) ? (int)$line['caseSize'] : 1);
            $item->unitSize(isset($line['unitSize']) ? (string)$line['unitSize'] : '');
            $item->brand(isset($line['brand']) ? (string)$line['brand'] : '');
            $item->description(isset($line['description']) ? (string)$line['description'] : '');
            $upc = isset($line['upc']) ? (string)$line['upc'] : '';
            if ($upc !== '' && class_exists('BarcodeLib')) {
                $upc = BarcodeLib::padUPC($upc);
            }
            $item->internalUPC($upc);
            $item->save();
        }

        return $orderId;
    }

    /**
     * Look up an existing PO by (vendorID, vendorInvoiceID).
     *
     * @param SQLManager $dbc
     * @param int $vendorId
     * @param string $invoiceNumber
     * @return int|null orderID if found
     */
    private function findExistingPoId($dbc, $vendorId, $invoiceNumber)
    {
        if ($invoiceNumber === '') {
            return null;
        }
        $p = $dbc->prepare('SELECT orderID FROM PurchaseOrder WHERE vendorID=? AND vendorInvoiceID=? LIMIT 1');
        $row = $dbc->getRow($p, array($vendorId, $invoiceNumber));
        if (is_array($row) && isset($row['orderID'])) {
            return (int)$row['orderID'];
        }
        return null;
    }

    /**
     * Convert a date string to FANNIE's DATETIME format. Returns 'now' for empty input.
     *
     * @param string $raw
     * @return string
     */
    private function normalizeDate($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return date('Y-m-d H:i:s');
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return date('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
