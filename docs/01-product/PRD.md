# Product Requirements Document

## Product Name

Asset Maintenance Tracking System (ATMS)

## Product Objective

ATMS is a simple operational maintenance system that supports manual asset management, asset usage tracking, preventive maintenance, corrective maintenance, work order execution, and maintenance history. It integrates with the client ERP to read parts, while keeping a local operational copy for maintenance workflows. Assets are created and managed manually within ATMS — no ERP asset source exists for this client.

## Core Product Principle
ERP remains the master system for parts. ATMS is the master system for assets
and the operational maintenance layer.

ERP owns:

- Parts master records
- Capitalization
- Disposal
- Depreciation
- Financial asset treatment
- Procurement and warehouse accounting

ATMS owns:

- Asset master records (fully managed in ATMS — no ERP asset source)
- Asset usage readings
- Asset physical location and location history
- Preventive maintenance rules
- Maintenance requests
- Maintenance Manager approval
- Work orders
- Parts used on work orders
- Asset and parts attachments
- Maintenance history

## Core Workflow

The core workflow is:

**Maintenance Request → Review and Approval → Work Order → Execution → Closure → Maintenance History**

There are two maintenance types:

### Preventive Maintenance

Preventive Maintenance Requests are generated automatically by the system based on configured PM rules. PM rules may be based on date intervals, operating hours, kilometers, or other usage readings.

### Corrective Maintenance

Corrective Maintenance Requests are created manually by users when an asset is faulty, damaged, underperforming, or requires repair.

## Main Navigation

The locked navigation is:

1. Dashboard
2. Assets
3. Work Orders
4. Parts Reference
5. PM Rules
6. Administration

## In-Scope Summary

- ERP parts integration
- Manual asset registry (create, update, manage locally)
- Parts reference database
- Asset usage tracking
- Physical location tracking and history
- Preventive maintenance rules
- Automatic PM request generation
- Corrective maintenance requests
- Maintenance Manager review and approval
- Work order management
- Parts usage on work orders
- Work order closure
- Asset and parts attachments
- Maintenance history
- Dashboard and basic reporting
- User roles and access control
- System settings
- Dropdown/master data management

## Out-of-Scope Summary

- Financial asset management
- Procurement and purchasing
- Full warehouse/inventory management
- Parts costing and financial tracking
- Labor tracking
- Technician wallet/personal stock
- Logistics, gate passes, and asset transfer documents
- Handover management
- Advanced governance/audit module
- Advanced checklist management
- Full document management system
- Native mobile application
- QR/barcode scanning
- IoT/automatic meter reading
- Advanced predictive maintenance
- Multi-level approval workflow
- External workflow notifications, excluding the included activation and
  password-reset emails delivered through Microsoft Power Automate
- Advanced BI/reporting
- ERP write-back
- Offline mode
- Multi-tenant SaaS features
