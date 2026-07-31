# Backend Coding Standards

## General

- Keep controllers thin.
- Put business operations in Action or Service classes.
- Validate input using Form Request classes.
- Shape API responses using Resources.
- Use Enums or constants for statuses and types.
- Write tests for workflow transitions.

## Naming

Use clear domain names:

- MaintenanceRequest
- WorkOrder
- PmRule
- Asset
- Part
- Location
- AssetMeterReading
- AssetLocationHistory
- Attachment
- ErpSyncJob

## Workflow Operations

Implement important workflow operations as explicit actions:

- ApproveMaintenanceRequestAndCreateWorkOrder
- RejectMaintenanceRequest
- CloseWorkOrder
- GeneratePreventiveMaintenanceRequest
- RecordAssetMeterReading
- UpdateAssetLocation

## Database

- Use migrations for all schema changes.
- Add indexes for ERP identifiers and frequently filtered statuses.
- Use foreign keys where practical.
- Use JSONB only for flexible ERP raw payloads or flexible metadata.
- Avoid storing financial cost fields in MVP.

## Testing Priorities

Minimum tests:

- Corrective MR can be created.
- PM evaluation can create preventive MR.
- MR approval creates WO.
- MR rejection does not create WO.
- WO closure updates relevant history.
- Location update creates history record.
- ERP asset sync upserts assets.
- ERP parts sync upserts parts.

- Component can be installed into a package.
- Component can be removed from a package.
- Component swap is atomic (remove + install in one transaction).
- Cycle prevention rejects self-referencing parent assignment.
- Component hours are derived correctly on removal (parent reading − install time).
- Installed component has sub-status "Installed"; spare component has "Ready".
- Assembly history records are created on install and closed on removal.
