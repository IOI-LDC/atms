# Out of Scope

The proposed system is intended to be a simple operational maintenance application. The following items are excluded from the current scope unless agreed separately.

## 1. Financial Asset Management

The system will not manage asset capitalization, depreciation, disposal, impairment, asset valuation, book value, or accounting treatment of fixed assets. These activities will remain fully within the ERP.

## 2. Procurement and Purchasing

The system will not handle purchase requests, purchase orders, supplier quotations, goods receiving, invoicing, payment approvals, or supplier management.

## 3. Store / Inventory / Warehouse Management

Parts inventory, stock-on-hand, stock movement, warehouse operations, and stock counting are owned by the SM (Store Management) subsystem — not ATMS. ATMS reads parts from SM only to populate a Work Order part-request form.

## 4. Parts Costing and Financial Tracking

The system will not calculate maintenance financial cost, asset cost, stock value, or accounting cost. Parts usage may be recorded operationally against a work order, but financial costing will remain outside the system.

## 5. Labor Tracking

The system will not track technician labor hours, labor cost, timesheets, technician productivity, or labor-based maintenance costing.

This exclusion includes hour logs, labor rates, labor costs, timesheets,
attendance-derived maintenance effort, utilization metrics, and productivity
reporting.

## 6. Technician Wallet / Personal Stock

The system will not track parts personally held by technicians as individual wallets. Any advanced process for issuing parts to technicians, returning unused materials, or managing partial consumables can be considered later if required.

## 7. Asset Physical Movement / Logistics

Asset physical movement requests, movement approvals, arrival confirmations, and
location history are owned by the AM (Asset Movement) subsystem. The system will
not include logistics workflows, gate passes, shipment manifests, delivery
notes, bulk transfers, transport approvals, or movement documents within ATMS.

The Logistics role within ATMS is limited to reading asset locations provided by
AM. Movement workflow operations (submit, approve, confirm arrival) are
performed in the AM frontend.

## 8. Handover Management

Shift handovers, crew handovers, site handover notes, and operational handover approvals are excluded.

## 9. Advanced Governance and Audit Module

The system will not include a dedicated governance module, audit campaign
management, compliance workflows, or advanced approval chains. A lightweight
append-only technical audit log is included for security-sensitive and workflow
actions, but a full audit/governance interface is excluded.

## 10. Advanced Checklist Management (Revised — Client-Requested Reversal)

**A configurable Work Order execution form (WO Form) is now in scope** (client-requested). The WO Form feature provides boolean, numeric, and text fields with optional display units, pre/post-maintenance value capture, mapping by FA subclass (`fa_subclass_code`), snapshot-on-WO-create, and sync-to-latest. See [WO_FORMS.md](./WO_FORMS.md) for the full specification.

The following advanced checklist capabilities remain **excluded**:

- Mandatory photo checklists — forms contain typed values only.
- Pass/fail scoring — boolean fields are simple true/false, not scored.
- Checklist versioning approvals — template edits are direct Admin changes.
- Checklist-based defect generation — form values do not auto-create MRs or trigger workflows.
- Any form engine beyond the documented WO Form scope (single active form per subclass; no conditional logic, scoring, or multi-form stacking).

A simple Work Order completion note remains available for all Work Orders regardless of whether a WO Form exists.

## 11. Full Document Management System

Basic attachments against assets are included. However, the system will not act as a full document management system. Advanced document versioning, approval workflows, document expiry alerts, controlled document distribution, e-signatures, and document lifecycle management are excluded unless agreed separately.

## 12. Mobile Application

A dedicated native mobile application is excluded unless agreed separately. The first version is assumed to be a web application, with responsive screens where practical.

## 13. QR Code / Barcode Scanning

QR code generation, barcode scanning, asset label printing, and scan-to-open asset records are excluded from the base scope unless added as a separate requirement.

## 14. IoT / Automatic Meter Reading

The system will not automatically read operating hours, kilometers, or sensor values from machines, GPS devices, telemetry platforms, or IoT systems. Usage readings will be entered manually or imported only if a simple data source is available.

## 15. Advanced Preventive Maintenance Optimization

The system will support simple preventive maintenance triggering based on date, operating hours, kilometers, or other readings. Advanced predictive maintenance, AI-based failure prediction, condition-based monitoring, and optimization algorithms are excluded.

MVP PM Rules apply only to individual ATMS-managed assets. Category-level,
asset-type-level, unit/package-level, group, and reusable template rules are
excluded unless approved as later scope.

## 16. Multi-Level Approval Workflow

The base workflow includes Maintenance Manager review and approval before a maintenance request becomes a work order. Multi-level approvals, delegation rules, approval limits, and complex authorization matrices are excluded.

## 17. External Notifications

Workflow emails, SMS, WhatsApp, push notifications, and escalation reminders
are excluded unless agreed separately. The only included external notifications
are account activation and password-reset emails delivered through Microsoft
Power Automate. Basic in-system status visibility can be provided.

## 18. Advanced Reporting and BI

The system will include only basic operational dashboards and simple reports. Advanced analytics, custom report builders, Power BI dashboards, financial analysis, and KPI packs are excluded.

## 19. ERP Write-Back

The system will not update the ERP with financial data, capitalization, disposal,
or maintenance records unless agreed separately. ERP integration for parts is
currently read-only (synced from ERP into SM tables).

> **Under discussion with LDC:** Parts consumption write-back — when SM issues
> a part to a requester at Goods Receipt (stock exits store), SM may push a
> consumption transaction to ERP so the ERP reflects the inventory decrement.
> Mechanism and API contract are pending LDC ERP team input. See
> [`sm/01-product/LDC_MEETING_PARTS_WRITEBACK.md`](../sm/01-product/LDC_MEETING_PARTS_WRITEBACK.md).

No ERP asset sync exists — assets are managed fully within ATMS.

## 20. Offline Mode

Offline working, offline synchronization, and conflict resolution are excluded from the initial scope.

## 21. Multi-Tenant SaaS Features

The system is assumed to be deployed for a single client environment. Multi-tenant SaaS billing, tenant self-registration, subscription management, and tenant-level commercial controls are excluded.

## 22. SharePoint Application Hosting And SSO

The product will not be deployed into SharePoint SitePages, implemented as an SPFx web
part, embedded in SharePoint, or deeply integrated with the SharePoint user
interface. SharePoint or Microsoft Entra authentication and automatic access
based on portal membership are excluded from MVP.

SharePoint is limited to employee-directory import and a normal company-portal
link to the separately hosted product application. Power Automate remains the
production transport for activation and password-reset emails.
