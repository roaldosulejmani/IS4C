<?php
/*******************************************************************************

    HRR Foods Plugin — Manifest

    Registers the plugin with FANNIE and declares every user-configurable
    setting. Settings are version=2 (DB-backed via the PluginSettings table)
    and namespaced under HRRFoods.* so they never collide with other plugins.

    When HRR Foods ships their real REST API, only the values in the
    $plugin_settings['HRRApiBaseUrl'] / ['HRROAuthScope'] entries need to
    change, plus the field-name assumptions in HRRCatalogNormalizer and
    HRRInvoiceImport.

    Copyright 2026 HRR Foods LLC
    Source-available license — see LICENSE in the plugin root for terms.

*********************************************************************************/

if (!class_exists('FannieAPI')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FannieAPI.php');
}
if (!class_exists('COREPOS\\Fannie\\API\\FanniePlugin')) {
    include_once(dirname(__FILE__) . '/../../../classlib2.0/FanniePlugin.php');
}

class HRRFoods extends \COREPOS\Fannie\API\FanniePlugin
{
    public $version = 2;
    public $settingsNamespace = 'HRRFoods';

    public $plugin_settings = array(
        'HRRApiBaseUrl' => array(
            'label' => 'API Base URL',
            'default' => 'https://api.hrrfoods.com/v1',
            'description' => 'Root URL of the HRR REST API. Updated when HRR ships their endpoint.',
        ),
        'HRROAuthClientId' => array(
            'label' => 'OAuth Client ID',
            'default' => '',
            'description' => 'OAuth2 client_id issued by HRR to your co-op.',
        ),
        'HRROAuthClientSecret' => array(
            'label' => 'OAuth Client Secret',
            'default' => '',
            'description' => 'OAuth2 client_secret. Stored in the DB, never written to logs.',
        ),
        'HRROAuthScope' => array(
            'label' => 'OAuth Scope',
            'default' => 'catalog:read purchase_order:write invoice:read',
            'description' => 'Space-separated scope list. HRR will publish the canonical list.',
        ),
        'HRRTokenUrl' => array(
            'label' => 'Token Endpoint',
            'default' => 'https://api.hrrfoods.com/v1/oauth/token',
            'description' => 'Override only if HRR uses a different auth host.',
        ),
        'HRRVendorID' => array(
            'label' => 'FANNIE Vendor ID',
            'default' => '',
            'description' => 'The vendorID in the vendors table for "HRR Foods". Create the vendor in Vendors first, then enter its ID here.',
        ),
        'HRRStoreMap' => array(
            'label' => 'Store -> Account Map (JSON)',
            'default' => '{}',
            'description' => 'JSON object mapping FANNIE storeID to HRR account code. Example: {"0":"CHICAGO-HQ","1":"CHICAGO-001","2":"ERIE-002"}.',
        ),
        'HRRDefaultStoreID' => array(
            'label' => 'Default Store ID',
            'default' => '0',
            'description' => 'FANNIE storeID used when no per-store context is available. 0 = HQ-default; only safe in single-store installs.',
        ),
        'HRRBatchSize' => array(
            'label' => 'Catalog Batch Size',
            'default' => '500',
            'description' => 'Page size for catalog/price pulls.',
        ),
        'HRRRateLimitPerMinute' => array(
            'label' => 'API Rate Limit (req/min)',
            'default' => '60',
            'description' => 'Maximum requests per minute. Token-bucket client honors this.',
        ),
        'HRRMaxRetries' => array(
            'label' => 'Max Retries',
            'default' => '3',
            'description' => 'Retries on 429/5xx with exponential backoff.',
        ),
        'HRRRequestTimeoutSec' => array(
            'label' => 'HTTP Timeout (seconds)',
            'default' => '30',
            'description' => 'Per-request cURL timeout.',
        ),
        'HRRInvoiceLookbackDays' => array(
            'label' => 'Invoice Lookback (days)',
            'default' => '14',
            'description' => 'How far back to scan for new invoices.',
        ),
        'HRRDryRun' => array(
            'label' => 'Dry Run (capture only)',
            'default' => '0',
            'description' => 'When 1, all outbound POST/PUT requests are written to noauto/dryrun/ as JSON files instead of being sent. Useful for first-install review with HRR support.',
        ),
        'HRRImageCacheEnabled' => array(
            'label' => 'Cache Images Locally',
            'default' => '0',
            'description' => '0 (recommended) = write HRR image URL to productUser.photo only. 1 = also download binaries to noauto/images/ (adds ~2GB for 42k SKUs).',
        ),
        'HRRPriceUpdateMode' => array(
            'label' => 'Price Update Mode',
            'default' => 'vendor_only',
            'description' => 'off = skip price pulls. vendor_only = update vendorItems.cost only. products_too = also update products.cost and log via ProdUpdateModel.',
        ),
        'HRRLogLevel' => array(
            'label' => 'Log Level',
            'default' => 'info',
            'description' => 'debug | info | warning | error. Debug logs redact secrets.',
        ),
        'HRRContactEmail' => array(
            'label' => 'Co-op Contact Email',
            'default' => '',
            'description' => 'Used in the User-Agent header so HRR support can identify a misbehaving client.',
        ),
        'HRRUserAgent' => array(
            'label' => 'HTTP User-Agent',
            'default' => 'Fannie-HRRFoods/1.0',
            'description' => 'Override only if HRR requires a specific format. The contact email is appended automatically.',
        ),
    );

    public $plugin_description = 'HRR Foods (Schiller Park, IL) integration. Catalog + price pull, PO submission, invoice pull. v1 supports REST/JSON; HRR API endpoint TBD.';
}
