<?php
/*******************************************************************************

    HRR Foods Plugin — HRR product JSON to FANNIE row

    Translates a single HRR product JSON document into a row that can be
    UPSERTed into vendorItems (PK = (sku, vendorID)). Also returns the image
    URL and SRP separately so callers can decide whether to write to products
    or productUser.

    Field map is a best-guess for HRR's eventual API, modeled on SPS Commerce
    / KeHE / UNFI. When HRR publishes their real schema, change this file.

    Per-line math mirrors CpwPriceTask.php:70-81 (case-to-each conversion).

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRRCatalogNormalizer
{
    /**
     * Build a vendorItems row from an HRR product JSON document.
     *
     * Expected input shape (best guess):
     *   {
     *     "upc": "0123456789012",
     *     "vendorSku": "HRR-998877",
     *     "brand": "Acme",
     *     "description": "Organic Peanut Butter",
     *     "size": "12 OZ",
     *     "caseSize": 12,
     *     "department": 14,
     *     "pricing": {
     *       "caseCost": 24.00,
     *       "saleCaseCost": 18.00,
     *       "onSale": true,
     *       "suggestedRetail": 2.99
     *     },
     *     "image": { "url": "https://cdn.hrrfoods.com/.../sku-998877.jpg" }
     *   }
     *
     * @param array $product HRR product document
     * @param int $vendorId FANNIE vendorID
     * @return array{row: array<string,mixed>, upc: string, imageUrl: ?string, srp: float}
     *         The 'row' keys map directly to vendorItems columns.
     */
    public function toVendorItemRow(array $product, $vendorId)
    {
        $vendorId = (int)$vendorId;
        $upc = isset($product['upc']) ? (string)$product['upc'] : '';
        $sku = isset($product['vendorSku']) ? (string)$product['vendorSku'] : '';
        $brand = isset($product['brand']) ? (string)$product['brand'] : '';
        $description = isset($product['description']) ? (string)$product['description'] : '';
        $size = isset($product['size']) ? (string)$product['size'] : '';
        $caseSize = isset($product['caseSize']) ? (int)$product['caseSize'] : 1;
        if ($caseSize < 1) {
            $caseSize = 1;
        }
        $vendorDept = isset($product['department']) ? (int)$product['department'] : 0;

        $pricing = isset($product['pricing']) && is_array($product['pricing'])
            ? $product['pricing']
            : array();
        $caseCost = isset($pricing['caseCost']) ? (float)$pricing['caseCost'] : 0.0;
        $saleCaseCost = isset($pricing['saleCaseCost']) ? (float)$pricing['saleCaseCost'] : 0.0;
        $onSale = !empty($pricing['onSale']);
        $srp = isset($pricing['suggestedRetail']) ? (float)$pricing['suggestedRetail'] : 0.0;

        // case-to-each, per CpwPriceTask.php:70-81.
        $costEach = $caseSize > 0 ? round($caseCost / $caseSize, 4) : 0.0;
        $saleEach = ($onSale && $caseSize > 0) ? round($saleCaseCost / $caseSize, 4) : 0.0;

        $imageUrl = null;
        if (isset($product['image']['url']) && is_string($product['image']['url'])) {
            $imageUrl = trim($product['image']['url']);
            if ($imageUrl === '') {
                $imageUrl = null;
            }
        }

        $row = array(
            'upc' => $upc,
            'sku' => $sku,
            'brand' => $brand,
            'description' => $description,
            'size' => $size,
            'units' => $caseSize,
            'cost' => $costEach,
            'saleCost' => $saleEach,
            'vendorDept' => $vendorDept,
            'vendorID' => $vendorId,
            'srp' => $srp,
        );

        return array(
            'row' => $row,
            'upc' => $upc,
            'imageUrl' => $imageUrl,
            'srp' => $srp,
        );
    }

    /**
     * Build a products.cost update for HQ mode. Returns the values array
     * for: UPDATE products SET cost=?, modified=NOW() WHERE upc=? AND store_id=?
     *
     * Only the cost field is touched; the co-op owns normal_price / sale_price.
     *
     * @param array $row the row from toVendorItemRow() above
     * @param int $storeId FANNIE storeID
     * @return array{cost: float, upc: string, storeId: int}
     */
    public function toProductCostUpdate(array $row, $storeId)
    {
        return array(
            'cost' => (float)$row['cost'],
            'upc' => (string)$row['upc'],
            'storeId' => (int)$storeId,
        );
    }

    /**
     * Build a productUser.photo update. Returns the URL to write, or null if
     * the source product has no image.
     *
     * @param string|null $imageUrl from toVendorItemRow()
     * @param int $storeId FANNIE storeID
     * @return array{photo: string, upc: string, storeId: int}|null
     */
    public function toProductUserPhotoUpdate($imageUrl, $storeId)
    {
        if (!$imageUrl) {
            return null;
        }
        // productUser.photo is VARCHAR(255); reject URLs that won't fit.
        if (strlen($imageUrl) > 255) {
            return null;
        }
        return array(
            'photo' => $imageUrl,
            'upc' => '', // caller must supply the upc; this stub is just for shape
            'storeId' => (int)$storeId,
        );
    }
}
