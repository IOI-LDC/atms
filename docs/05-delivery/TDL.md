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

### 7. ✅ Does BC have Store Order / Store Management live?

| Field | Value |
|---|---|
| **Resolved** | 2026-06-25 — VJ confirmed BC has **no** Store Order / Store Management module. Parts issuance flows through **Warehouse Management transactions**. By the documented decision rule, this means **build the SM subsystem as planned**. The "integrate on top of BC Warehouse" alternative was evaluated and declined (high coupling, scope creep, BC Warehouse is the client's execution layer — SM only needs a narrow consumption write-back). |
| **VJ reply** | Appended to [`ERP_STORE_ORDER_QUESTION.md`](../sm/01-product/ERP_STORE_ORDER_QUESTION.md) |
| **Follow-up** | [`ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md) — warehouse write-back questions |

> **Decision:** Build SM as a focused maintenance-consumption module. BC
> Warehouse remains the inventory source of truth; SM posts a consumption
> write-back at Goods Receipt (see #8).

---

### 8. Parts read URL + QTY update on consumption 🔴

Two things from VJ, framed as outcomes (not BC internals — VJ owns the "how"):

1. **Read URL** for all M&S, Consumables, and Parts from BC (same Entra ID
   token as fixed assets).
2. **QTY update on consumption** — when a part is consumed in SM/ATMS, can the
   part's quantity in BC be decremented? If yes, what handoff does VJ need
   from us (API call, record/file, or ERP-side setup)? We will provide part
   code, qty consumed, date, and reference.

| Field | Value |
|---|---|
| **Blocked since** | 2026-06-25 |
| **Depends on** | VJ (ERP Consultant) |
| **What we need** | (1) Parts/consumables/M&S read URL; (2) confirmation that QTY can be updated in BC on consumption + the handoff format VJ requires from us. |
| **Phase** | Phase 2 (SM build). Phase 1 parts reference is read-only and unaffected. |
| **Impact if QTY update possible** | SM consumption flows straight into BC; ERP inventory stays accurate. |
| **Impact if QTY update not possible** | SM maintains its own balances; reconciliation with BC becomes manual/periodic. |
| **Message** | [`ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md) |

---

### 8. ✅ Virtual Store — design questions resolved

| Field | Value |
|---|---|
| **Resolved** | 2026-06-24 — Q1: One Virtual Store per workshop (LDC has one). Q2: Manager approves per part/line item. Q3: System auto-flags at end of day. Q4: Covered by auto-flagging on SM dashboard. |
| **Spec** | [`docs/sm/01-product/VIRTUAL_STORE.md`](../sm/01-product/VIRTUAL_STORE.md) |

---

### 9. ✅ `asset_tag` field

| **Resolved** | 2026-06-24 — Format `L-BBB-CCC-XXXX`. Ownership (L/X), type code (3-char from faSubclassCode), size code (encoded inch measurement or 000), serial suffix (last 4 of serialNo). Manual generation, immutable after save, unique constraint. See [`ASSET_TAG.md`](../atms/01-product/ASSET_TAG.md). |

---

## Resolved

| # | Item | Date |
|---|---|---|
| — | Token auth working (Entra ID → BC) | 2026-06-24 ✅ |
| — | BC Store Order question — VJ confirmed no Store Order module; SM to be built as planned | 2026-06-25 ✅ |
| — | Fixed assets endpoint confirmed (`fixedAssestAPI`, 429 assets, 24 fields) | 2026-06-24 ✅ |
| — | Asset Assembly model: Q1–Q5 all decided | 2026-06-24 ✅ |
| — | Mock ERP deprecated, config/infra cleaned | 2026-06-24 ✅ |
| — | Documentation restructure (3 subsystems, 5 roles) | 2026-06-24 ✅ |
| — | LDC Meeting Parts Write-Back document | 2026-06-24 ✅ |
