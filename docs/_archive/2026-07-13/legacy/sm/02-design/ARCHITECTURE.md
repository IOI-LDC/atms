# Store Management (SM) — Architecture

> **Status:** Placeholder. SM has no code yet.

SM shares the **single Laravel backend and PostgreSQL database** with ATMS and AM (see `docs/03-backend/ARCHITECTURE.md`). There is one backend service, one queue, one scheduler, and one database for all three subsystems.

## Source of truth

- **Parts catalogue** lives in SM tables — the authoritative source for all parts reference data across the product family.
- **Inventory balances and stock movement** are owned exclusively by SM.

## ERP integration boundary

SM owns ERP parts sync (`SyncErpPartsJob`). ERP-owned part columns (`erp_part_id`, `erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at`) are never writable through the API. See `docs/03-backend/ERP_SYNC.md`.
