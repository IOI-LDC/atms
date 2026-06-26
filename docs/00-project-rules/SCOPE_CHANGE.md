# Scope Change — Original Proposal vs. Current Reality

> **Purpose:** Structured diff between the original Inova proposal (18-day AMTS)
> and the current agreed scope. Designed for an AI to generate an updated
> technical and financial proposal.
>
> **How to use this document:** Feed it to a proposal-writing AI with the
> instruction: *"This is a scope change from the original proposal. Generate a
> revised proposal with updated technical architecture, timeline, and pricing."*

---

## 1. High-Level Changes

| Dimension | Original Proposal | Current Scope |
|---|---|---|
| **Product name** | AMTS (Asset Maintenance and Tracking System) | ATMS (Asset Maintenance Tracking System) — the maintenance subsystem of a three-system product family |
| **Architecture** | Single application | Three subsystems (ATMS + SM + AM) sharing one backend + one database |
| **Timeline** | 18 working days (4 phases) | Expanded — to be estimated |
| **RBAC roles** | 4 (Administrator, Manager, Technician, Viewer) | 5 (Administrator, Maintenance Manager, Technician, Logistics, Requester). Viewer merged into Requester. |
| **ERP direction** | Read-only pull (assets + parts) | Read-only pull for parts (SM-owned). No ERP asset sync. Write-back for parts GR under discussion. |

---

## 2. Scope Diff — Per Module

### 2.1 Changed Modules

| # | Module | Original | Current | Rationale |
|---|---|---|---|---|
| 1 | ERP Integration | Read-only sync for fixed assets AND parts | Parts only — synced into SM tables. No ERP asset sync. Assets created and managed within ATMS. | LDC ERP does not have an asset source; assets are managed manually. Write-back at GR under discussion. |
| 2 | Operational Asset Registry | Tracks physical location, usage readings, status, history | Same, plus: Asset Maintenance Status (Active/Inactive + sub-statuses), Asset Assembly (parent/child relationships), Asset Tag (physical label format `L-BBB-CCC-XXXX`) | Client requested richer asset modeling: assemblies, printable tags, detailed status tracking. |
| 4 | Asset Usage Tracking | Operating hours, kilometers, custom meters | Same, plus component hours derived from parent readings + install timestamps | Required for assembly model — components accumulate hours while installed in a parent. |
| 5 | Location Tracking | ATMS tracks location changes with audit trail | AM subsystem owns location. ATMS reads location from AM tables for display. AM workflow: Requester → Logistics approve → confirm arrival. | Client requested formal movement approval workflow with Logistics role. |
| 6 | Preventive Maintenance Rules | Rules based on time/usage intervals | Same, plus: parent + component PM rules run independently. Cross-check at parent WO service with green/yellow/red indicators. | Package PM and component PM are independent schedules — no auto-cascade. |
| 11 | Parts Usage | Record ERP-linked parts consumed during work | ATMS reads parts from SM catalogue. WO part-request submits into SM's Order workflow. SM manages inventory, stock movement, ERP sync. | Client requested full store management capability — not just a reference list. |
| 16 | User Roles & Access | 4 roles listed | 5 roles. Logistics added for AM movement approvals. Requester added as baseline (all users). Viewer removed. Role-specific assembly permissions. | Movement workflow requires Logistics. All staff are Requesters. Viewer was redundant. |

### 2.2 Added Modules (not in original proposal)

| # | New Module | Description | Rationale |
|---|---|---|---|
| 19 | Store Management (SM) Subsystem | Parts catalogue, inventory balances, stock movement, Order → Approval → Dispatch → Goods Receipt workflow. ERP parts sync owned by SM. | Client needed operational store workflow beyond a passive parts list. |

> ✅ **RESOLVED (2026-06-25):** VJ (ERP Consultant) confirmed BC has **no**
> Store Order / Store Management module — parts issuance flows through
> Warehouse Management transactions. Per the decision rule above, **SM is built
> as a full subsystem as planned.** The "integrate on top of BC Warehouse"
> alternative was evaluated and declined (BC Warehouse stays the client's
> execution layer; SM only needs a narrow consumption write-back at Goods
> Receipt). See [`sm/01-product/ERP_STORE_ORDER_QUESTION.md`](../sm/01-product/ERP_STORE_ORDER_QUESTION.md)
> for VJ's reply and [`sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md)
> for the open warehouse write-back questions.
| 20 | Asset Movement (AM) Subsystem | Movement request workflow, location history, arrival confirmation. Source of truth for asset location across all subsystems. | Client needed formal movement tracking with Logistics approval. |
| 21 | Asset Assembly (Package / Component) | Assets composed of other assets with independent maintenance lifecycles. Parent-child relationships, install/remove/swap operations, `asset_assembly_history` audit table, component operating hours derivation. | Client has equipment like mud motors where rotor and stator are independently maintained assets that can be swapped between motors. |
| 22 | Asset Maintenance Status | Active (standalone / Installed / Ready) and Inactive (LIH, DBR, Disposed, Scrapped, Other). Independent of ERP financial treatment. | Client needed clearer asset lifecycle states beyond simple active/inactive. |
| 23 | Asset Tag | Physical label format `L-BBB-CCC-XXXX` (ownership, type code, size code, serial suffix). Database-unique, immutable after print, designed for QR code scanning. | Client needed a physical-world identifier that field staff, vendors, and the ERP can all reference. |
| 24 | Parts Consumption Write-Back | When SM completes a Goods Receipt (item issued to requester, exits store), SM pushes the consumption transaction (stock decrement) to LDC ERP. ERP reflects updated inventory. | Client needs ERP to know when store stock is consumed so inventory records stay accurate across systems. |
| 25 | Virtual Store (Workshop Stock) | Workshop locations with bins for staging parts during a shift. Daily transfer-in → consumption → return cycle. End-of-day auto-flagging. Manager-approved overnight holds (must consume next day, else auto-return). | Technicians need parts at hand in the workshop without walking to the main store for every item. Accountability: stock cannot sit indefinitely in the workshop. |
| 26 | Component PM Cross-Check | When a parent WO is open, child components show 🟢🟡🔴 PM status indicators. Manual "Create MR for Component" action for yellow/red items. | Improves workshop efficiency — catch component issues while the parent is already in for service. |

### 2.3 Removed / Changed Constraints

| # | Original | Current | Rationale |
|---|---|---|---|
| — | MinIO object storage for attachments | Excluded from MVP. Laravel local storage on persistent Docker volume. | Simplifies deployment. MinIO is optional future upgrade. |
| — | SharePoint as deployed environment | SharePoint is a portal link only. Application is separately hosted. No SSO. | Client confirmed SharePoint is not the deployment target. |
| — | "Type-safe compiled backend framework" | Laravel 13 (PHP 8.4) — the framework chosen and implemented. | Proposal language was generic. Implementation is Laravel. |
| — | "Slate" color palette | Design system: deep navy (`--primary: 221.4 32% 17.5%`), deep rose, deep purple. shadcn-vue components. | Design system was refined during implementation. |
| — | 18-day timeline | To be re-estimated. Added scope includes 2 new subsystems, assembly model, tag system, expanded RBAC. | Original estimate predates scope expansion. |

---

## 2. Implementation Phases

The system will be delivered in two phases to prioritise operational maintenance
and defer store/inventory complexity.

### Phase 1 — ATMS Core (Operational Maintenance)

| Scope item | Detail |
|---|---|
| Asset registry | Full asset CRUD with maintenance status (Active/Inactive + sub-statuses), asset tags (`L-BBB-CCC-XXXX`), operational fields |
| Maintenance workflows | Corrective + Preventive MRs, Manager approval → Work Order → execution → closure. Full status model. |
| Preventive maintenance | PM rules are reusable schedule **templates** (date / reading / date_or_reading) explicitly **assigned** to individual assets via an `asset_pm_assignments` pivot; each assignment carries its own compliance baseline. `EvaluatePmRulesJob` iterates active assignments and generates PM MRs when due. Template lifecycle is Admin-only; assignment lifecycle is Admin + Maintenance Manager. This is still explicit per-asset assignment — **not** category/type auto-apply (which remains out of scope). See `.kilo/plans/1782413031648-pm-rules-mn-refactor.md`. |
| Parts reference | Parts catalogue seeded from ERP sync into SM tables. Read-only in Phase 1 — no ordering, no inventory, no stock movement. ATMS WOs reference parts by part ID for consumption recording. |
| Asset location | Logistics role can update `asset.location_id` directly. No movement request workflow. No approval chain. Location history written by existing `UpdateAssetLocation` Action. |
| RBAC | 5 roles: Administrator, Maintenance Manager, Technician, Logistics, Requester |
| Dashboard & reporting | KPI cards, MR/WO status summaries, maintenance history |
| Attachments | Laravel local storage on persistent Docker volume |

**Out of Phase 1 (deferred to Phase 2):**
Asset Assembly (parent/child), Component PM cross-check, SM Order workflow,
SM inventory/stock movement, Virtual Store, AM movement approval workflow,
ERP parts write-back, MinIO object storage.

### Phase 2 — Store Management + Asset Movement + Assembly

| Scope item | Detail |
|---|---|
| Asset Assembly | `parent_asset_id`, install/remove/swap Actions, `asset_assembly_history`, component hours derivation |
| Component PM cross-check | 🟢🟡🔴 indicators on parent WO. Manual "Create MR for Component" for yellow/red items. |
| SM subsystem | Full store management: Order → Approval → Dispatch → Goods Receipt workflow. Inventory balances, stock movement. Virtual Store (workshop stock). |
| AM subsystem | Movement request workflow: Requester → Logistics approve → confirm arrival. Formal location history with audit trail. |
| ERP write-back | Parts consumption (GR) pushed from SM to BC ERP (pending LDC confirmation) |
| ERP parts sync | Full bidirectional awareness — ERP is source of truth for parts, SM owns consumption |

**Phase 2 dependencies:** ~~VJ's reply on BC Store Order determines SM
architecture~~ — **RESOLVED 2026-06-25:** VJ confirmed no Store Order; SM built
as full subsystem. Remaining dependency: BC Warehouse consumption write-back
mechanism (correct transaction type + OData write support) — see
[`sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md`](../sm/01-product/ERP_WAREHOUSE_FOLLOWUP.md)
and TDL #8.

---

---

## 4. Architecture Impact

### From single app to three subsystems

```
Original:  ┌──────────┐
           │   AMTS   │  (one app)
           └──────────┘

Current:   ┌──────┐  ┌────┐  ┌────┐
           │ ATMS │  │ SM │  │ AM │  (three frontends)
           └──┬───┘  └─┬──┘  └─┬──┘
              │         │       │
              └────┬────┴───────┘
                   │
           ┌───────┴───────┐
           │   Backend     │  (one Laravel API)
           │   PostgreSQL  │  (one database)
           └───────────────┘
```

### Subsystem ownership boundaries

| Domain | Owned by |
|---|---|
| Assets, MRs, WOs, PM rules, dashboard, RBAC | ATMS |
| Parts catalogue, inventory, stock movement, ERP parts sync | SM |
| Asset location, location history, movement workflow | AM |
| Auth, audit log, attachments, scheduler, queue | Shared backend |

### RBAC expansion

| Role | Original | Current | Primary subsystem |
|---|---|---|---|
| Administrator | ✅ | ✅ | All |
| Maintenance Manager | ✅ | ✅ | ATMS |
| Technician | ✅ | ✅ | ATMS |
| Logistics | ❌ | ✅ New | AM |
| Requester | ❌ | ✅ New | ATMS (baseline for all users) |
| Viewer | ✅ | ❌ Removed | (merged into Requester) |

---

## 5. Effort Impact — What Changes for Estimating

### Net-new backend work

| Item | Complexity |
|---|---|
| `asset_kind` + `parent_asset_id` columns on assets table | Medium |
| `asset_assembly_history` table + API | Medium |
| `asset_tag` column + validation + uniqueness | Low |
| Asset Maintenance Status sub-status enum + consistency rules | Low |
| Install / Remove / Swap component Actions (cycle prevention, hours derivation) | High |
| Component PM cross-check endpoint (children + PM status calc) | Medium |
| SM Order workflow endpoints (Order → Approval → Dispatch → GR) | High |
| AM movement workflow endpoints (submit → approve → confirm arrival) | High |
| ERP `ErpSource` contract simplified to parts-only | Low |
| `LdcErpHttpSource` adapter (Entra ID OAuth2 + BC OData V4) | Medium |
| 5-role seeders and policies (replace 6-role) | Low |

### Net-new frontend work

| Item | Complexity |
|---|---|
| ATMS: AssemblyTree, InstallComponentSheet, RemoveComponentDialog, SwapComponentSheet | High |
| ATMS: ComponentAssemblyHistory timeline, AssetKindBadge, ParentAssetLink | Medium |
| ATMS: Component PM status indicators on parent WO screen | Medium |
| ATMS: Asset tag display + create/edit form integration | Low |
| SM: Full Store Management frontend (catalogue, inventory, Order→Approval→Dispatch→GR) | High |
| AM: Full Asset Movement frontend (movement form, approval, arrival confirmation, history) | High |
| Shared: Navigation updated for 3-subsystem + 5-role visibility | Low |

### Timeline impact

The original 18-day timeline covered a single application with read-only ERP
integration and 4 roles. The current scope adds two complete subsystems, an
assembly model, physical asset tagging, and expanded RBAC. A realistic
re-estimate should separate ATMS core from SM and AM, which can be phased.

---

## 6. Financial Impact Considerations

> **For the consultant to complete.**

- **ATMS core** (original scope adjusted): ERP parts sync (SM-owned), asset
  registry with assembly + tags + status, PM rules with component cross-check,
  5-role RBAC. Roughly equivalent to original 18-day estimate with scope
  adjustments.
- **SM subsystem** (net new): Full store management build — Order → Approval
  → Dispatch → Goods Receipt, inventory, Virtual Store. **Scope confirmed
  2026-06-25** (VJ confirmed no BC Store Order). Optional ERP write-back at GR
  (BC Warehouse transaction) is a separate, smaller effort pending the
  warehouse review session with VJ.
- **AM subsystem** (net new): Asset movement frontend + backend. Movement
  workflow, location history. New estimate required.
- **ERP write-back** (under discussion): If parts GR write-back to ERP is
  confirmed, additional integration effort. TBD after LDC meeting.
- **Infrastructure:** Docker Compose unchanged. No MinIO (reduces cost).
  SharePoint integration reduced to portal link (reduces cost).

---

## 7. Key Documents Referenced

| Document | Purpose |
|---|---|
| `docs/atms/01-product/PRD.md` | ATMS product scope |
| `docs/atms/01-product/ASSET_ASSEMBLY.md` | Assembly spec (all 5 questions resolved) |
| `docs/atms/01-product/ASSET_TAG.md` | Tag format spec |
| `docs/atms/01-product/ASSET_STATUS.md` | Maintenance status spec |
| `docs/sm/01-product/PRD.md` | SM scope (placeholder) |
| `docs/am/01-product/PRD.md` | AM scope (placeholder) |
| `docs/03-backend/ARCHITECTURE.md` | Shared backend architecture |
| `docs/03-backend/RBAC.md` | 5-role permission matrix |
| `docs/03-backend/ERP_SYNC.md` | ERP integration (Entra ID + BC OData V4) |
| `docs/MOC_SCOPE_RESTRUCTURE.md` | Full management of change document |
| `docs/atms/01-product/IN_SCOPE.md` | Full in-scope item list |
| `docs/atms/01-product/OUT_OF_SCOPE.md` | Full exclusions list |
