<?php
/*******************************************************************************

    HRR Foods Plugin — Endpoint Constants

    Industry-convention paths modeled on SPS Commerce / KeHE / UNFI public APIs.
    When HRR Foods publishes their real REST API, only this file plus
    HRRCatalogNormalizer and HRRInvoiceImport should need to change.

    All paths are relative. HRRApiClient prepends the configured base URL.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

namespace COREPOS\Fannie\Plugin\HRRFoods;

class HRREndpoints
{
    /** Unauthenticated health probe. */
    const HEALTH = '/health';

    /** OAuth2 token endpoint. */
    const OAUTH_TOKEN = '/oauth/token';

    /** Paginated catalog. Query: since, page, size, store (optional). */
    const CATALOG_PRODUCTS = '/catalog/products';

    /** Single product detail. */
    const CATALOG_PRODUCT = '/catalog/products/{upc}';

    /** Per-account price. Query: store (required). */
    const CATALOG_PRODUCT_PRICE = '/catalog/products/{upc}/price';

    /** Image URL. Returns {url, updated_at}. */
    const CATALOG_PRODUCT_IMAGE = '/catalog/products/{upc}/image';

    /** Submit a PO. Body carries accountCode. */
    const PURCHASE_ORDERS = '/purchase-orders';

    /** HRR-side PO status. Query: store (required). */
    const PURCHASE_ORDER = '/purchase-orders/{poNumber}';

    /** List invoices. Query: store (required), since (optional). */
    const INVOICES = '/invoices';

    /** Single invoice with line items. Query: store (required). */
    const INVOICE = '/invoices/{invoiceId}';

    /**
     * Substitute path parameters. Unknown {placeholders} are left verbatim
     * so missing context surfaces as a clearly broken URL.
     *
     * @param string $template e.g. "/catalog/products/{upc}/image"
     * @param array<string,scalar> $params e.g. ['upc' => '0123456789012']
     * @return string
     */
    public static function fill($template, array $params)
    {
        foreach ($params as $key => $value) {
            $template = str_replace('{' . $key . '}', (string)$value, $template);
        }
        return $template;
    }
}
