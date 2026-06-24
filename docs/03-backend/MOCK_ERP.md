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

- [ ] Remove mock ERP Docker Compose service and profile
- [ ] Remove `config/mock-erp.php`
- [ ] Remove `MockErpHttpSource` adapter class
- [ ] Remove `MOCK_ERP_URL` / `MOCK_ERP_API_KEY` environment variables
- [ ] Remove mock ERP seed data and migrations
- [ ] Remove mock ERP contract tests
