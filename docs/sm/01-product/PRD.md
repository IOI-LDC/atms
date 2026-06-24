# Store Management (SM) — Product Requirements

> **Status:** Placeholder. SM is not yet built. This document captures agreed scope so the docs tree is not broken.

## Purpose

Store Management (SM) is the subsystem that owns the **parts catalogue and warehouse/inventory operations** shared across the product family. It is a peer subsystem to ATMS and AM, sharing a single Laravel backend and PostgreSQL database.

## Scope

SM is responsible for:

- **Parts catalogue** — the canonical parts table. Parts are synced from the LDC ERP into SM tables (`SyncErpPartsJob`). ERP-owned columns are never writable through the API; only local operational fields are editable.
- **Inventory balances** — stock on hand per part/location.
- **Stock movement** — issues, receipts, transfers, adjustments.
- **Order workflow** — Order → Approval → Dispatch → Goods Receipt (GR).
- **ERP parts sync** — SM owns the ERP integration boundary for parts. ATMS reads parts from SM tables only.
- **Consumption write-back** — When SM completes a Goods Receipt (item issued to
  requester, stock exits store), SM pushes the consumption transaction to LDC
  ERP so the ERP reflects the inventory decrement. This is a proposed write-back
  — mechanism and API contract to be confirmed with LDC ERP team. See
  [`LDC_MEETING_PARTS_WRITEBACK.md`](./LDC_MEETING_PARTS_WRITEBACK.md).

## Relationship to ATMS

ATMS does not own parts. When a Work Order records a consumed part, ATMS reads the part from the SM parts table to populate a part-request form; submission of that form flows into SM's order/stock workflow. ATMS never writes to SM inventory tables directly.

## Out of scope for SM (MVP)

- Costing/valuation, procurement, multi-warehouse custody handovers (owned by future phases).
