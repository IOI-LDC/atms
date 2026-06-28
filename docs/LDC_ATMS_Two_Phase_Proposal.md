# LDC ATMS Revised Two-Phase Proposal

**Prepared for:** LDC  
**Prepared by:** Inova  
**Subject:** Revised delivery approach for ATMS, Parts, and Location scope  
**Date:** June 2026

---

## 1. Executive Summary

Following the initial ATMS proposal and the additional requirements discussed with LDC, we recommend delivering the system in two phases.

The original proposal was for a focused Asset Maintenance Tracking System. Following the additional requirements discussed with LDC, the scope has expanded to cover advanced asset operations, parts consumption integration, formal asset movement, and future-ready asset identification. To keep delivery controlled and to avoid delaying the core maintenance system, we recommend separating the work into two clear phases.

Phase 1 keeps the focus on the operational maintenance foundation. It delivers the core ATMS capabilities required by LDC to manage asset maintenance records, maintenance requests, work orders, preventive maintenance, parts used on work orders, location updates, attachments, role-based access, and basic reporting. This allows the maintenance team to begin using the system for day-to-day operations without waiting for the more advanced workflows.

Phase 2 builds on the Phase 1 foundation by adding advanced operational capabilities, including Asset Assembly, component-level maintenance visibility, formal asset movement workflow, and the consumed-parts handoff required by the ERP team to update inventory quantities in Dynamics 365 Business Central.

The proposed financial effort is **18 working days for Phase 1** and **14 working days for Phase 2**, giving a **base total of 32 working days**. If LDC also requires the optional Full Store Management add-on, this would add **5 working days**, bringing the total financial effort to **37 working days**.

The solution is structured around three functional areas:

| Area | Purpose |
|---|---|
| **ATMS** | Asset maintenance operations: asset registry, maintenance requests, work orders, preventive maintenance, attachments, dashboard, and role-based access. |
| **Parts** | Parts reference data, parts used on work orders, and consumed-parts handoff to ERP. |
| **Location** | Asset current location, location history, and future asset movement workflow. |

ERP / Dynamics 365 Business Central remains the source of truth for asset and parts reference data, inventory quantities, and warehouse transactions. ATMS will not duplicate ERP inventory management. ATMS will own the operational maintenance layer, including asset maintenance status, asset tags, maintenance requests, work orders, readings, attachments, location activity, and parts consumed during maintenance.

ATMS will provide the required consumed-parts data to the ERP team so inventory quantities can be updated in Dynamics BC through warehouse transactions.

This proposal keeps full Store Management outside the base two-phase delivery. If LDC requires ATMS to include a full store workflow, inventory balances, and stock movement screens, this can be added as a separate optional add-on.

---

## 2. Proposed Delivery Phases

### Phase 1 - ATMS Core Operational Maintenance

**Commercial effort:** **18 working days**

Phase 1 delivers the core maintenance system required to operate ATMS.

#### In Scope

| Area | Scope |
|---|---|
| Asset registry | Asset records aligned with ERP reference data, plus ATMS-owned asset tags, maintenance status, operational status, usage readings, attachments, and maintenance history. |
| Asset Tag | Official asset tag format for physical identification, searchable asset lookup, and future QR code enablement. |
| Preventive maintenance | PM rules, scheduled evaluation, PM-generated maintenance requests, and due / overdue tracking. |
| Corrective maintenance | Users can raise corrective maintenance requests for faulty, damaged, underperforming, or repair-required assets. |
| Maintenance approval | Maintenance Manager approval / rejection before work orders are created. |
| Work orders | Work order creation from approved requests, assignment, execution, completion, closure, notes, readings, and attachments. |
| Parts reference | Parts catalogue reference from ERP / Parts tables for work order usage. |
| Parts used on work orders | Recording consumed parts against work orders, including quantity and work order reference. |
| Simple location update | Logistics can update an asset location directly in Phase 1. No approval chain is included in this phase. |
| Location history | Location changes are captured for audit and operational reference. |
| Role-based access | Five fixed roles: Administrator, Maintenance Manager, Technician, Logistics, and Requester. |
| Dashboard and reporting | Initial operational dashboard and simple maintenance reports. |
| Attachments | Local attachment storage for assets, maintenance requests, and work orders. |

#### Out of Scope for Phase 1

- Asset Assembly parent / child install, remove, and swap workflow.
- Component PM cross-check.
- Full asset movement approval workflow.
- Full Store Management.
- Inventory balances inside ATMS.
- Stock movement inside ATMS.
- Order -> Approval -> Dispatch -> Goods Receipt workflow.
- ERP inventory updates performed directly by ATMS.
- Native mobile application.
- QR code scanning and asset label printing implementation.
- Advanced BI, custom KPI builder, or Power BI dashboard.
- Labour tracking, technician timesheets, labour cost, or productivity costing.
- Procurement, purchasing, supplier management, invoicing, or financial asset accounting.

---

### Phase 2 - Advanced Operations and ERP Consumption Integration

**Commercial effort:** **14 working days**

Phase 2 adds advanced operational control and ERP consumption integration while keeping ERP as the source of truth for inventory and warehouse transactions.

#### In Scope

| Area | Scope |
|---|---|
| Asset Assembly | Parent / child asset relationships, install, remove, swap, and assembly history. |
| Component hours | Derivation of component operating hours from parent asset readings and installation period. |
| Component PM cross-check | Component status indicators on parent work orders, with manual maintenance request creation for flagged components. |
| Full Location workflow | Requester submits movement request -> Logistics approves -> Logistics confirms arrival -> location history is updated. |
| Consumed-parts API / export | ATMS exposes consumed work-order parts to the ERP team for inventory quantity updates in Dynamics BC. |
| ERP consumption handoff | Consumption handoff includes work order reference, part code, quantity consumed, consumption date, and any additional fields requested by the ERP team. |

#### ERP Quantity Update Responsibility

The ERP team will handle inventory quantity updates inside Dynamics BC. The expected integration model is:

1. ATMS records parts consumed against work orders.
2. ATMS provides consumed-parts data through an agreed API or export.
3. The ERP team processes the consumption through Dynamics BC warehouse transactions.
4. Inventory quantities are updated periodically in ERP.

The update frequency will be confirmed with LDC and the ERP team. Possible options include daily, bidaily, or weekly updates.

#### Out of Scope for Phase 2

- ATMS-owned inventory balance management.
- ATMS-owned warehouse transactions.
- Store stock counting.
- Financial stock valuation.
- Procurement and supplier workflow.
- Full Store Management unless LDC approves the optional add-on below.

---

## 3. Optional Add-On - Full Store Management

**Additional commercial effort:** **5 working days**

This add-on is not included in Phase 1 or Phase 2. It should only be included if LDC wants ATMS to manage store operations beyond consumed-parts reporting.

#### Optional In Scope

| Area | Scope |
|---|---|
| Store order workflow | Order -> Approval -> Dispatch -> Goods Receipt. |
| Inventory balances | ATMS-side stock-on-hand views and balances. |
| Stock movement | Receipts, issues, transfers, adjustments, and movement history. |
| Store screens | Storekeeper / warehouse operational screens. |

#### Recommendation

Based on the current understanding that Dynamics BC is the source of truth for inventory and warehouse transactions, full Store Management is not required for the main ATMS delivery. It should remain optional unless LDC confirms a separate operational need for ATMS-side store control.

---

## 4. Asset Tag Proposal

Asset Tag was previously proposed to the CFO. We recommend making it an official part of the ATMS Phase 1 scope.

### Purpose

Each asset should have a short, human-readable, physically printable tag. The tag links the physical asset in the workshop or field to its digital record in ATMS.

### Benefits

- Faster asset identification during maintenance.
- Less dependency on long serial numbers or inconsistent asset descriptions.
- Easier communication between field staff, technicians, maintenance managers, logistics, and finance.
- Better search and filtering inside ATMS.
- Stronger physical-to-digital traceability.
- Future-ready structure for QR code integration.

### Proposed Format

The proposed tag format is:

```text
L-BBB-CCC-XXXX
```

| Segment | Meaning |
|---|---|
| `L` / `X` | Ownership indicator: LDC-owned or external. |
| `BBB` | Asset type code. |
| `CCC` | Size code or `000` if not applicable. |
| `XXXX` | Serial suffix or unique identifier suffix. |

Example:

```text
L-MTR-958-0011
```

### Future QR Code Integration

The asset tag is designed so it can later be encoded into a QR code. A future QR scan can open the relevant ATMS asset page and display information allowed by the user's role.

Based on the proposed scope, the QR-linked asset page can display:

- Asset tag.
- Asset name and description.
- Serial number, model, manufacturer, and asset type.
- Maintenance status and operational status.
- Current location.
- Latest usage readings.
- Open maintenance requests.
- Active or recent work orders.
- Preventive maintenance due / overdue status.
- Maintenance history.
- Attachments available to the user's role.
- Phase 2 assembly information, if enabled: parent asset, child components, install / remove / swap history, and component PM status.

QR code generation, label printing, and scan-to-open workflow are not included in the base Phase 1 scope unless LDC asks to include them. The Phase 1 asset tag design prepares the data structure so QR integration can be added later without redesigning asset identity.

---

## 5. Summary

This revised proposal separates the expanded LDC requirements into a controlled two-phase delivery model with a base effort of **32 working days**. The approach allows LDC to receive the operational ATMS core first, while keeping the more advanced capabilities structured as a planned second phase rather than mixing all requirements into one large delivery.

The key benefit of this approach is that LDC can start using the maintenance platform for day-to-day operations earlier. Phase 1 establishes the foundation for asset maintenance records, maintenance requests, work orders, preventive maintenance, parts used on work orders, simple location updates, role-based access, attachments, and initial dashboard/reporting capability. This gives Maintenance, Logistics, and management teams a single operational view of maintenance activity.

Phase 2 then extends that foundation with stronger operational control, including Asset Assembly, component-level maintenance visibility, the formal asset movement workflow, and the consumed-parts handoff required for the ERP team to update inventory quantities in Dynamics 365 Business Central.

Full Store Management is positioned as an optional enhancement because Dynamics BC remains the source of truth for inventory quantities and warehouse transactions. However, adding Store Management would give LDC a more complete operational workflow inside ATMS, covering store requests, approvals, dispatch, goods receipt, inventory visibility, and stock movement tracking. This would create a stronger end-to-end connection between Maintenance, Logistics, and Store teams, while still allowing ERP to remain the financial and inventory system of record.

This gives LDC a practical path forward: deliver the core maintenance system first, retain a clear route for advanced asset operations and ERP consumption integration, and keep the option open to expand into a fuller Store Management workflow if LDC wants tighter operational control over the parts request and issue process.
