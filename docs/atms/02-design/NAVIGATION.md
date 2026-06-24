# Navigation Model

The navigation should remain intentionally simple.

## Main Navigation

1. Dashboard
2. Assets
3. Work Orders
4. Parts Reference
5. PM Rules
6. Administration

## Dashboard

Purpose: operational overview.

Typical cards:

- Pending Maintenance Requests
- Open Work Orders
- Overdue PMs
- Assets Due for Maintenance
- Recently Closed Work Orders
- Recently Updated Assets

## Assets

Purpose: local operational asset registry based on ERP fixed assets.

Asset area should include:

- Asset Registry
- Asset Detail
- Usage & Meter Readings
- Location History
- Maintenance History
- Attachments
- ERP Reference Data

## Work Orders

Purpose: manage Maintenance Requests and Work Orders in one module.

Tabs:

- Pending Requests
- Active Work Orders
- Closed Work Orders

## Parts Reference

Purpose: read-only or mostly read-only SM parts reference database.

Part detail should include:

- Part overview
- ERP reference fields
- Attachments/manuals/datasheets
- Usage references where applicable

## PM Rules

Purpose: configure rules for generating Preventive Maintenance Requests.

PM rules can be based on:

- Calendar interval
- Operating hours
- Kilometers
- Other usage readings
- Whichever comes first

## Administration

Purpose: system configuration and administration.

Sub-sections:

- Users & Fixed Role Assignment
- Master Data
- Locations
- ERP Sync Settings
- Company Settings

Location and Master Data management are Administrator-only. Maintenance
Managers and Logistics users select existing active locations through
operational asset screens.

Administrator and Maintenance Manager may run a manual ERP sync. ERP Sync
Settings are Administrator-only.
