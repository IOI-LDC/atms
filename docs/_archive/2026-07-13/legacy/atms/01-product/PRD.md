# Product Requirements Document

## Product Name

ATMS (Asset Maintenance Tracking System) — the maintenance subsystem of the
product family. ATMS shares its backend and database with SM (Store Management)
and AM (Asset Movement).

## Product Objective

ATMS is a simple operational maintenance system that supports manual asset
management, asset usage tracking, preventive maintenance, corrective
maintenance, work order execution, and maintenance history. Parts are supplied
by the SM (Store Management) subsystem — ATMS reads parts only to populate a
Work Order part-request form. Asset location is supplied by the AM (Asset
Movement) subsystem — ATMS reads the current location for display.

## Core Product Principle

ATMS is the master system for **assets and the operational maintenance layer**.
Parts reference data is owned by **SM** (synced from ERP into SM tables). Asset
location is owned by **AM**.

ERP owns (external to the product family):

- Capitalization
- Disposal
- Depreciation
- Financial asset treatment
- Procurement and warehouse accounting

SM owns:

- Parts master records (synced from ERP)
- Inventory balances and stock movement
- Parts order workflow (Order → Approval → Dispatch → GR)

AM owns:

- Asset current location and location history
- Asset movement workflow (Requester submits → Logistics approves → Logistics confirms arrival)

ATMS owns:

- Asset master records (fully managed in ATMS — no ERP asset source)
- Asset usage readings
- Asset maintenance status (`enrolled` / `withdrawn` with sub-statuses)
- Asset assembly (Package / Component)
- Asset tagging (physical label format: L-BBB-CCC-XXXX) for identification and QR support
- Preventive maintenance rules
- Maintenance requests
- Maintenance Manager approval
- Work orders
- Attachments
- Work Order execution forms (configurable pre/post-maintenance forms mapped by FA subclass)
- Maintenance history

## Core Workflow

The core workflow is:

**Maintenance Request → Review and Approval → Work Order → Execution → Closure → Maintenance History**

There are two maintenance types:

### Preventive Maintenance

Preventive Maintenance Requests are generated automatically by the system based
on configured PM rules. PM rules may be based on date intervals, operating
hours, kilometers, or other usage readings.

### Corrective Maintenance

Corrective Maintenance Requests are created manually by users when an asset is
faulty, damaged, underperforming, or requires repair.

## Navigation

See [NAVIGATION.md](../02-design/NAVIGATION.md) for the authoritative sidebar
and tab structure.

## In-Scope Summary

- Manual asset registry (create, update, manage locally)
- Asset maintenance status (`enrolled` / `withdrawn` with sub-statuses: `lih`, `dbr`, `disposed`, `scrapped`, `other`)
- Asset assembly (Package / Component)
- Asset tagging (physical label format: L-BBB-CCC-XXXX) for identification and QR support
- Asset usage tracking
- Preventive maintenance rules (applied to individual ATMS-managed assets)
- Automatic PM request generation
- Corrective maintenance requests
- Maintenance Manager review and approval
- Work order management
- Parts usage recording against work orders (parts read from SM)
- Work order closure
- Work Order execution forms (configurable pre/post-maintenance forms, mapped by FA subclass)
- Asset attachments
- Maintenance history (reads current location from AM tables, parts data from SM tables)
- Dashboard and basic reporting
- User roles and access control (5 roles)
- System settings
- Dropdown/master data management

## Out-of-Scope Summary

- Financial asset management
- Procurement and purchasing
- Warehouse/inventory management (owned by SM)
- Parts costing and financial tracking
- Labor tracking
- Technician wallet/personal stock
- Asset physical movement tracking (owned by AM)
- Gate passes, shipments, transport documents (owned by AM)
- Handover management
- Advanced governance/audit module
- Advanced checklist management (excludes the in-scope configurable WO Form — see [OUT_OF_SCOPE.md](./OUT_OF_SCOPE.md) §10 for the revised boundary)
- Full document management system
- Native mobile application
- QR/barcode scanning
- IoT/automatic meter reading
- Advanced predictive maintenance
- Multi-level approval workflow
- External workflow notifications, excluding the included activation and
  password-reset emails delivered through Microsoft Graph `sendMail`
- Advanced BI/reporting
- ERP write-back
- Offline mode
- Multi-tenant SaaS features
