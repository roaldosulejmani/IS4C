# HRR Foods — FANNIE Plugin

Integration between CORE-POS / FANNIE (this IS4C fork) and HRR Foods, a
grocery distributor based in Schiller Park, IL. The plugin lets any
co-op FANNIE installation:

- Submit Purchase Orders directly to HRR
- Pull closed invoices into FANNIE's existing A/P flow
- Pull HRR's product catalog (UPC, SKU, brand, case/cost, image URL)
- Refresh product images
- Walk every store account code so each store routes to its assigned DC

**Status:** built against industry-convention REST/JSON endpoint shapes
(modeled on SPS Commerce / KeHE / UNFI). HRR's public API is not yet
public — this plugin ships with a swappable base URL so when HRR
publishes their endpoint, an admin only needs to change the URL and
OAuth credentials.

**Version:** 1.0 (pre-HRR-API-ship)

---

## Quick Start

```bash
# 1. Enable plugin
Fannie → Plugins → HRRFoods → enable
# 2. Configure (see "Configuration" below)
# 3. Test Connection
HRR Foods Admin → Test Connection → expect "OK HTTP 200"
# 4. Bootstrap (one-time)
HRR Foods Admin → Run Bootstrap → populates vendorItems across all accounts
# 5. Cron schedule (see "Cron Schedule" below)
```

---

## Configuration

All settings live in `PluginSettings` (DB-backed, version-2 storage) and
are managed through Fannie → Plugins → HRRFoods. None of them live in
`config.php`.

| Setting | Required | Default | Description |
|---|---|---|---|
| `HRRApiBaseUrl` | yes | `https://api.hrrfoods.com/v1` | REST base URL. Update when HRR publishes their endpoint. |
| `HRROAuthClientId` | yes | (empty) | OAuth2 client_id from HRR. |
| `HRROAuthClientSecret` | yes | (empty) | OAuth2 client_secret. Never logged, never displayed in plain text. |
| `HRROAuthScope` | no | `catalog:read purchase_order:write invoice:read` | Space-separated scope list. |
| `HRRTokenUrl` | no | `https://api.hrrfoods.com/v1/oauth/token` | Override only if HRR uses a separate auth host. |
| `HRRVendorID` | yes | (empty) | The `vendorID` in the FANNIE `vendors` table for "HRR Foods". Create the vendor first. |
| `HRRStoreMap` | yes | `{}` | JSON map `{"1":"CHICAGO-001","2":"ERIE-002"}`. See below. |
| `HRRDefaultStoreID` | yes | `0` | FANNIE storeID used when no per-store context is available (HQ mode). |
| `HRRBatchSize` | no | `500` | Page size for catalog/price pulls. |
| `HRRRateLimitPerMinute` | no | `60` | Token-bucket capacity. |
| `HRRMaxRetries` | no | `3` | Retries on 408/429/5xx with exponential backoff. |
| `HRRRequestTimeoutSec` | no | `30` | Per-request cURL timeout. |
| `HRRInvoiceLookbackDays` | no | `14` | How far back to scan for new invoices. |
| `HRRDryRun` | no | `0` | `1` = write outbound requests to `noauto/dryrun/` instead of sending. |
| `HRRImageCacheEnabled` | no | `0` | `1` = also download image binaries to `noauto/images/`. |
| `HRRPriceUpdateMode` | no | `vendor_only` | `off` / `vendor_only` / `products_too`. |
| `HRRLogLevel` | no | `info` | `debug` / `info` / `warning` / `error`. |
| `HRRContactEmail` | no | (empty) | Used in the User-Agent header. |
| `HRRUserAgent` | no | `Fannie-HRRFoods/1.0` | Override only if HRR requires a specific format. |

### Store → Account Map (`HRRStoreMap`)

A JSON object that maps a FANNIE `storeID` (integer, string-form OK) to an
HRR account code (`[A-Za-z0-9._-]{1,64}`). One co-op FANNIE install, one
map, one OAuth client. HRR assigns each account to a DC, so account
codes correspond to DCs.

Example:
```json
{"0":"CHICAGO-HQ","1":"CHICAGO-001","2":"ERIE-002","3":"LEXINGTON-003"}
```

Validation rules:
- Must be valid JSON.
- Must be a JSON object, not a list.
- Keys must parse as integers (use `"1"`, not `"store-1"`).
- Values must be non-empty strings matching `[A-Za-z0-9._-]{1,64}`.

Use the admin page's "Store→Account Map" form for validation feedback.
The admin page only accepts entries that pass the regex; an entry with a
space or other disallowed character is rejected with a clear error.

---

## PO Submission

1. Create a PO in Fannie → Purchasing against the HRR vendor (the
   `vendorID` you set in `HRRVendorID`).
2. In the PO detail view, use the "Export" dropdown → **"HRR Foods (JSON)"**.
   (The dropdown is auto-populated from `fannie/purchasing/exporters/`.)
3. The exporter:
   - Generates the JSON body.
   - Writes a copy to `noauto/dryrun/po-{poID}-{ts}.json` for the paper trail.
   - Writes a copy to `noauto/po-queue/{poID}.json` as a defense-in-depth
     queue (defensive; the cron picks up via DB query normally).
   - Stamps `PurchaseOrder.placed = 1`, `placedDate = NOW()`.
   - Shows a confirmation: "PO queued for transmission — HRR will receive
     it within 5 minutes."
4. `HRRSendPurchaseOrderTask` (cron, every 5 min) finds all
   `placed=1 AND vendorOrderID IS NULL` POs, posts the JSON to
   `POST /purchase-orders`, and on success writes the returned
   `vendorOrderID` back to `PurchaseOrder`.

The route is `?id={poID}&export=HRRFoodsExport`, not `?sendAs=` —
`sendAs` runs an email flow we don't want. See `fannie/purchasing/ViewPurchaseOrders.php:217`
for the export handler.

### Stuck POs

If a PO has `placed=1 AND vendorOrderID IS NULL AND placedDate < NOW() - INTERVAL 1 HOUR`,
the admin page surfaces it as "Stuck". Common causes:
- HRR API down for >1 hour.
- Account code unmapped for the PO's store.
- 4xx error from HRR (see `noauto/logs/po-export.log` and `cronMsg()`
  history).

Click "Retry POs" to re-run the task on demand.

---

## Invoice Pull

`HRRPullInvoicesTask` (cron, nightly 02:00) walks every account code in
`HRRStoreMap`, fetches closed invoices for the past
`HRRInvoiceLookbackDays` days, and creates a `PurchaseOrder` with
`userID='HRR_AUTO'` for each new invoice.

Idempotency key: `(vendorID, vendorInvoiceID)`. Re-running the task
never duplicates an invoice — it skips any whose `vendorInvoiceID`
already exists in FANNIE.

If a matching PO exists (looked up via `vendorInvoiceID`), its lines
are updated in place rather than duplicated. This handles the "late
invoice correction" case where HRR sends an amended invoice for a PO
we already imported.

---

## Catalog / Pricing Pull

`HRRPullCatalogTask` (cron, weekly Sun 03:00) walks HRR's catalog from a
cursor stored at `noauto/.catalog-cursor.json` (default
`1970-01-01T00:00:00Z` — the bootstrap run resets to the epoch).

Each product:
- UPSERT into `vendorItems` keyed on `(sku, vendorID)` (per
  `CpwPriceTask.php:99-106`).
- If `vendorDept` is non-zero and not in `vendorDepartments` for this
  vendor, a row is inserted (per `CpwPriceTask.php:108-113`).

Pricing mode is controlled by `HRRPriceUpdateMode`:
- `off` — skip catalog pull entirely.
- `vendor_only` (default) — only `vendorItems.cost` / `vendorItems.saleCost` change.
- `products_too` — also writes `products.cost` and `products.special_cost`
  and calls `ProdUpdateModel::logManyUpdates($upcs, 'EDIT')` for the
  audit trail.

Retail `normal_price` is **never** touched by HRR — co-ops set their own
retail.

### Multi-store / HQ mode

When `STORE_MODE == 'HQ'`, the catalog task additionally walks every
account code and calls `fetchPrice(upc, accountCode)` per account, writing
per-store rows to `products` keyed on `(upc, store_id)`. Two stores
sharing a cost produce one row; only changes get logged.

`HRRBootstrapTask` is the one-shot CLI equivalent that walks every
account code regardless of cursor and is intended for the very first
install. Run from the admin page's "Run Bootstrap" button.

---

## Image Pull

`HRRPullImagesTask` (cron, weekly Mon 03:00) walks `vendorItems` rows
with a UPC and no existing `productUser.photo`, calls `fetchImage(upc)`,
and writes the URL to `productUser.photo`.

Lanes display the image by hitting HRR's CDN at scan time. This is
fine because FANNIE installs already require internet for price lookups
— no new dependency.

If `HRRImageCacheEnabled=1`, the image binary is also downloaded to
`noauto/images/{upc}.jpg` and the local path is written to
`productUser.photo`. v1 ships URL-only; opt in only if you need offline
lane displays (note: ~2 GB for 42k SKUs, plus sync work).

---

## Dry-Run Mode

Set `HRRDryRun=1` and the plugin stops sending real HTTP requests.
Instead, every outbound POST/PUT is written to:

```
modules/plugins2.0/HRRFoods/noauto/dryrun/{ts}-{METHOD}-{path-slug}.json
```

with the shape:
```json
{
  "request": {"method": "POST", "url": "...", "headers": {...}, "body": "...", "timeout": 30, "timestamp": "..."}
}
```

The transport returns `202 Accepted` with `{dryRun: true}` so the
caller doesn't fail. Use this on first install:

1. Set `HRRDryRun=1`.
2. Run Bootstrap → produces dry-run JSON files for every catalog call.
3. Send those files to HRR support as a "this is what we'd send you"
   sample.
4. Once HRR confirms the format, set `HRRDryRun=0`.

---

## Admin Page

`modules/plugins2.0/HRRFoods/noauto/admin/HRRFoodsAdminPage.php` provides
operational UI on top of the same settings:

- **Connection** — read-only display of masked credentials, mapped
  store count, dry-run state, and a "Test Connection" button.
- **Run Tasks** — buttons for one-off execution of each task via `exec()`.
  Useful during initial setup.
- **Stuck POs** — PO list with age in hours.
- **Dry-Run Files** — last 10 files in `noauto/dryrun/`.
- **Recent Cron Output** — last 20 lines from `noauto/logs/po-export.log`.

Link it into FANNIE's menu by adding an entry to your custom admin nav.

---

## Cron Schedule

**Important:** `FannieTask.php:259` does `include(dirname(__FILE__).'/../config.php')`
relative to `fannie/classlib2.0/`. Cron **must** `cd` to that directory
before invoking `php FannieTask.php <TaskClass>`, or wrap the call in a
script that does so. Example wrapper:

```bash
#!/bin/sh
cd /var/www/html/fannie/classlib2.0
exec php FannieTask.php "$@"
```

Recommended crontab:

```cron
*/5 * * * *  /path/to/wrap.sh HRRSendPurchaseOrderTask
0 2 * * *    /path/to/wrap.sh HRRPullInvoicesTask
0 3 * * 0    /path/to/wrap.sh HRRPullCatalogTask
0 3 * * 1    /path/to/wrap.sh HRRPullImagesTask
```

`HRRBootstrapTask` is one-shot — run it from the admin page once.

---

## Endpoint Reference

The plugin targets these REST endpoints (industry convention; update
`src/HRREndpoints.php` if HRR ships with different paths):

```
GET    /health                                       — {status,version} (no auth)
POST   /oauth/token                                  — {access_token,expires_in,...}
GET    /catalog/products?since=&page=&size=&store=   — paginated products
GET    /catalog/products/{upc}?store=                — single product
GET    /catalog/products/{upc}/price?store=          — per-account price
GET    /catalog/products/{upc}/image                 — {url,updated_at}
POST   /purchase-orders                              — body carries accountCode
GET    /purchase-orders/{poNumber}?store=            — HRR-side PO status
GET    /invoices?store=&since=                       — list invoices
GET    /invoices/{invoiceId}?store=                  — invoice detail
```

The per-store account code travels in three places (belt-and-braces):
1. `?store=` query param on GETs.
2. `accountCode` top-level field in POST bodies.
3. `X-HRR-Account` HTTP header.

If HRR ships a different convention, the only file to touch is
`src/HRREndpoints.php` (and possibly the body shape in
`src/HRRCatalogNormalizer.php` and `fannie/purchasing/importers/HRRInvoiceImport.php`).

---

## Logging

- `noauto/logs/po-export.log` — every PO export event (append-only).
- `noauto/logs/` (cron outputs) — written via `cronMsg()`.
- `noauto/dryrun/` — dry-run captured requests.
- `noauto/po-queue/` — defense-in-depth queue of exported-but-unsent POs.
- `noauto/recordings/` — recordings from `RecordingHRRHttpTransport`
  (tests only).
- `noauto/.token-cache.json` — OAuth token cache (chmod 0600).
- `noauto/.catalog-cursor.json` — incremental catalog cursor.

`FannieLogger::WARNING` and `FannieLogger::ERROR` cron messages also
get surfaced via FANNIE's standard cron-message email list (see
Fannie → Tasks → Cron Messages).

---

## Security Notes

- OAuth `client_secret` is stored in the `PluginSettings` DB table. DB
  access controls apply. It is **never** displayed in plain text on the
  admin page (only the first 4 chars + `***`).
- The `Authorization: Bearer` header is **never** logged.
- All token cache files are `chmod 0600`.
- The redactor (`HRRAuth::redact`, `HRRApiClient::redactResponse`)
  strips `access_token`, `client_secret`, and `Authorization` from any
  payload before logging.
- The admin page is gated by FANNIE's standard admin auth.

---

## File Layout

```
fannie/modules/plugins2.0/HRRFoods/
├── HRRFoods.php                              Plugin manifest (v2 settings)
├── README.md                                 (this file)
├── noauto/
│   ├── admin/
│   │   ├── HRRFoodsAdminPage.php             Operator console
│   │   └── HRRFoodsTestConnectionAjax.php    Test Connection endpoint
│   ├── dryrun/                               Dry-run captured requests
│   ├── logs/                                 Cron logs
│   ├── po-queue/                             Defense-in-depth PO queue
│   └── recordings/                           Test recordings
├── src/
│   ├── HRREndpoints.php                      Endpoint path constants
│   ├── HRRHttpTransport.php                  Transport interface
│   ├── CurlHRRHttpTransport.php              Default cURL impl
│   ├── RecordingHRRHttpTransport.php         Test double
│   ├── FileWritingHRRHttpTransport.php       Dry-run impl
│   ├── HRRAuth.php                           OAuth2 token cache
│   ├── HRRApiClient.php                      REST client + retry/rate-limit
│   ├── HRRStoreMap.php                       Per-store account map
│   ├── HRRJson.php                           JSON helpers
│   ├── HRRCatalogNormalizer.php              Catalog → vendorItems row
│   └── HRRSettings.php                       Plugin settings loader
├── tasks/
│   ├── HRRSendPurchaseOrderTask.php          Cron: ship placed=1 POs
│   ├── HRRPullInvoicesTask.php               Cron: pull closed invoices
│   ├── HRRPullCatalogTask.php                Cron: catalog + price pull
│   ├── HRRPullImagesTask.php                 Cron: image URL refresh
│   └── HRRBootstrapTask.php                  One-shot: full chain pull
└── tests/
    ├── README.md                             Test docs
    ├── test_support.php                      Minimal test harness
    └── run_tests.php                         Test suite (php run_tests.php)
```

Other files this plugin touches outside its own directory:

- `fannie/purchasing/exporters/HRRFoodsExport.php` — PO JSON exporter,
  registered in the "Export" dropdown via `InventoryLib::orderExporters()`.
- `fannie/purchasing/importers/HRRInvoiceImport.php` — JSON invoice
  importer, called from `HRRPullInvoicesTask`.

---

## Testing

```bash
cd fannie/modules/plugins2.0/HRRFoods/tests
php run_tests.php
```

The test suite uses `RecordingHRRHttpTransport` and a 50-line
PHPUnit-free harness. See `tests/README.md` for the full list of cases.

---

## Roadmap (v1.1+)

Items deferred from v1:

- Lane-side image cache (currently URL-only).
- Per-store distinct retail pricing (co-ops set their own).
- Automatic batch creation / par-level POs.
- Accounting / GL integration (existing A/P path handles invoice import).
- Deleted-SKU sweep (cursor-over-pages can't easily detect deletions).
- Promo / off-invoice pricing (HRR promo API unknown).
- Image-update refresh (current task only fills missing photos).
- Backorder / substitution reconciliation.
- Real HRR API conformance — endpoint paths, OAuth scopes, JSON field
  names are best-guess. When HRR ships, expect to touch
  `src/HRREndpoints.php` and possibly `src/HRRCatalogNormalizer.php`
  and `fannie/purchasing/importers/HRRInvoiceImport.php`. The task
  logic, client surface, and admin UI are designed not to need changes.

---

## License

Copyright © 2026 HRR Foods LLC.

This plugin is **source-available**, not open source. It is licensed under
a Business-Source-License-style agreement: see `LICENSE` in this plugin's
root directory for the full text.

**Summary (not a substitute for the license):**

- **Use freely** for the Permitted Uses defined in the license:
  integrating Your co-op's CORE-POS / FANNIE install with HRR Foods
  LLC's wholesale ordering, catalog, pricing, invoicing, and image
  services.
- **Modify freely** for Your own co-op's needs.
- **Distribute within a co-op** (including multi-store co-op groups)
  and to POS vendors serving the cooperative grocery segment, subject
  to the same license terms.
- **Do NOT** use the plugin to build a competing wholesale/catalog
  service, or sublicense it as a stand-alone product.
- **Do NOT** use HRR Foods trademarks except to identify the origin of
  the plugin.
- **No warranty, no liability** from HRR Foods LLC.
- **Change date:** 2030-01-01. On and after that date the plugin
  automatically re-licenses under Apache License 2.0 for everyone.

The HRR Foods plugin is **not** licensed under the GNU GPL, the Apache
License, the MIT License, or any other OSI-approved open-source
license. The CORE-POS / FANNIE code that surrounds this plugin
remains under its own GPLv2-or-later license; this license governs
only the files inside `fannie/modules/plugins2.0/HRRFoods/` plus
`fannie/purchasing/exporters/HRRFoodsExport.php` and
`fannie/purchasing/importers/HRRInvoiceImport.php`.

Questions about the license: legal@hrrfoods.com.