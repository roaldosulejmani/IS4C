# HRR Foods Plugin — Tests

## What's covered

`run_tests.php` is a self-contained PHP test runner that exercises the
plugin's HTTP transport layer without needing a live API. It uses the
`RecordingHRRHttpTransport` test double to inject canned responses and
assert that the right requests are emitted.

Test cases:

- `HRRJson round-trip` — encode → decode → equality.
- `HRRJson decodeOr falls back` — invalid JSON returns default value.
- `HRRJson encode throws on invalid UTF-8` — encoding error path.
- `HRRStoreMap load and resolve` — basic mapping and null for unmapped.
- `HRRStoreMap rejects bad account codes` — regex on account code.
- `HRRStoreMap rejects non-object JSON` — list-style rejection.
- `HRRCatalogNormalizer per-each cost math` — `caseCost / caseSize`.
- `HRRCatalogNormalizer sale cost math` — saleCost mirrors formula.
- `HRRCatalogNormalizer missing pricing fields defaults safe` — no crash on partial JSON.
- `HRRApiClient testConnection happy path` — `/health` 200 → `{ok:true,httpCode:200}`.
- `HRRApiClient fetchCatalogPage stops at nextPage=null` — pagination termination.
- `HRRApiClient submitPurchaseOrder sends account code` — POST + body/header check.
- `HRRApiClient retries on 429 then succeeds` — backoff, retry count.
- `HRRApiClient does NOT retry on 400` — non-retriable 4xx.
- `HRRApiClient 401 forces token refresh and retries once` — refresh+retry flow.
- `HRRAuth redaction hides client_secret and access_token` — secret redaction.
- `FileWritingHRRHttpTransport writes files and returns 202` — dry-run mode.

## Running

```bash
cd fannie/modules/plugins2.0/HRRFoods/tests
php run_tests.php
```

Expected output: `[PASS] ...` lines, then `Passed: N, Failed: 0, Total: N`,
exit code 0. Any failure exits with code 1 and a `[FAIL]` line per failure.

The runner is intentionally not PHPUnit — FANNIE deployments don't always
have it installed, and pulling it in just to test this plugin is overkill.
The harness is ~50 lines and easy to read.

## Coverage gaps

These are NOT covered by `run_tests.php`:

1. **Full task run** (`HRRSendPurchaseOrderTask`, `HRRPullCatalogTask`,
   etc.) against a real DB. That's #14 (end-to-end verification) and
   needs the user's actual FANNIE install.
2. **Mock HTTP server**. The plan references `tests/mock_server.php`; this
   is deferred. With `RecordingHRRHttpTransport` covering request shape
   and response mapping, the mock server is only needed for the tasks
   themselves, which need a DB.
3. **The `CurlHRRHttpTransport` and `FileWritingHRRHttpTransport` cURL /
   disk paths.** The interfaces are covered; the concrete impls are
   trivial wrappers.