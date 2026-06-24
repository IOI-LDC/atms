# Task Delivery List

> **Date:** 2026-06-24
> **Purpose:** Track items blocked on external dependencies or pending decisions.

## Blocked — ERP Team

### 1. Parts API page name

Parts sync cannot proceed without the BC custom API page name for parts/items.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | ERP team / LDC |
| **What we need** | The OData V4 API page name — the equivalent of `fixedAssestAPI` but for parts (e.g. `itemsAPI`, `partsAPI`) |
| **Impact if resolved** | `SyncErpPartsJob` can fetch parts; field mapping can be documented; parts catalogue populated in SM |

### 2. Parts field mapping

Cannot map the parts schema until the endpoint returns data.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | #1 (Parts API page name) |
| **What we need** | Sample response rows showing field names and types for each part |

### 3. `componentOfMainAsset` sample data

BC has `mainAssetComponent` and `componentOfMainAsset` fields that may support the Asset Assembly model natively. We need to see a real example of a component asset (where `componentOfMainAsset` is non-null).

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-24 |
| **Depends on** | ERP team |
| **What we need** | An asset where `componentOfMainAsset` is set to a parent FA number, to confirm the ERP already models parent-child asset relationships |
| **Impact if resolved** | Asset Assembly model can map directly to BC's existing structure |

### 4. ✅ OData pagination behaviour

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — No pagination. BC returns all records in one response. Full pull, no cursor logic needed. |

### 5. ✅ Incremental sync support

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — Not needed. Pull all records and compare locally. No `$filter` required. |

### 6. ✅ `inactive` / `blocked` semantics

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — ERP `inactive`/`blocked` mapped to `erp_status` (informational only). Does **not** control `is_active`. Sync never overwrites ATMS local fields. Field ownership boundary documented in `ERP_SYNC.md`. |

---

## Pending — Internal Decisions

### 7. ✅ `asset_tag` field

| **Resolved** | 2026-06-24 — Format `L-BBB-CCC-XXXX`. Ownership (L/X), type code (3-char from faSubclassCode), size code (encoded inch measurement or 000), serial suffix (last 4 of serialNo). Manual generation, immutable after save, unique constraint. See [`ASSET_TAG.md`](../atms/01-product/ASSET_TAG.md). |

---

## Resolved

| # | Item | Date |
|---|---|---|
| — | Token auth working (Entra ID → BC) | 2026-06-24 ✅ |
| — | Fixed assets endpoint confirmed (`fixedAssestAPI`, 429 assets, 24 fields) | 2026-06-24 ✅ |
| — | Asset Assembly model: Q1–Q5 all decided | 2026-06-24 ✅ |
| — | Mock ERP deprecated, config/infra cleaned | 2026-06-24 ✅ |
| — | Documentation restructure (3 subsystems, 5 roles) | 2026-06-24 ✅ |
| — | LDC Meeting Parts Write-Back document | 2026-06-24 ✅ |
