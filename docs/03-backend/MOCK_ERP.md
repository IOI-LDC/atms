# Mock ERP Service — DEPRECATED

> **Status: Deprecated.** The real LDC ERP connection is now established
> (token auth working, asset endpoint confirmed, parts endpoint pending).
> The Mock ERP was a development stand-in used before the LDC ERP connection
> was available. It is no longer needed and should be removed from the
> codebase, Docker Compose, and configuration.

## Original Purpose (historical)

The project shipped a separate lightweight mock ERP service so the parts sync
could be developed and demonstrated when the client ERP connection was
unavailable. This is no longer relevant — we have the real LDC ERP.

## Cleanup Tasks

- [x] Remove mock ERP Docker Compose service and profile
- [x] Remove `config/mock-erp.php`
- [x] Remove `MockErpHttpSource` adapter class
- [x] Remove `MOCK_ERP_URL` / `MOCK_ERP_API_KEY` environment variables
- [x] Remove mock ERP seed data and migrations
- [x] Remove mock ERP contract tests

> ✅ **All cleanup tasks complete (Phase 1, 2026-06-25).** The real `LdcErpHttpSource` is now the live adapter, bound in `AppServiceProvider`. Environment uses `LDC_ERP_*` variables. Mock ERP is fully eradicated from the codebase.
