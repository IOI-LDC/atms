# Screen Inventory

## 1. Dashboard

Displays operational summary and quick links.

Required elements:

- Summary cards
- Pending MR list
- Open WO list
- Overdue PM list
- Recently closed WO list

## 2. Asset Registry

Displays ERP-linked asset list.

Required elements:

- Search
- Filters
- Asset table
- Status badge
- Current location
- Latest usage reading
- PM status
- Link to asset detail
- "Add Asset" action (Admin/Manager only)
- "Edit Asset" action per row (Admin/Manager only)

For Requesters, this screen is a limited active-asset lookup for creating
Corrective Maintenance Requests. Hide maintenance history, location history,
attachments, and ERP raw/reference details.

## 3. Asset Detail

Displays full operational profile of one asset.

Required sections:

- Overview
- ERP reference data
- Current physical location
- Usage readings
- Location history
- Maintenance history
- Attachments

Requester asset detail is a reduced view containing only basic active-asset
information required to submit a Corrective Maintenance Request.

## 4. Usage & Meter Readings

Displays and captures readings.

Required elements:

- Reading type
- Reading value
- Reading date/time
- Entered by
- Confirmation indicator
- Confirmed by, when confirmed
- Reading history

Use a simple `Unverified` indicator for readings without confirmation. Do not
introduce a multi-state reading workflow.

## 5. Location Update / Location History

Displays current location and past physical location changes.

Required fields for update:

- Current location
- New location
- Effective date
- Reason/notes

The update action is available only to Logistics, Maintenance Manager, and
Administrator. It records physical location history only.

## 6. Pending Maintenance Requests

Displays MRs awaiting Maintenance Manager review.

Required elements:

- MR number
- Type: PM or CM
- Asset
- Priority
- Requested/generated date
- Current status
- Approve/reject action

## 7. Review Maintenance Request

Used by Maintenance Manager.

While the request is pending_review, the creator (or Admin/Manager) may edit
the description, priority, and asset. Edits do not change the MR status.

Required actions:

- Approve & Create Work Order
- Reject Request
- Edit (description, priority, asset) — available to creator and Admin/Manager

Required information:

- Asset details
- Request description
- Origin: user/system
- PM rule trigger details where applicable
- Attachments where applicable

## 8. Active Work Orders

Displays approved work orders that are not closed.

Required elements:

- WO number
- Related MR
- Asset
- Status
- Assigned user/team
- Priority
- Created date

## 9. Work Order Detail

Used to execute and close work.

Required sections:

- Overview
- Related maintenance request
- Work notes
- Parts used
- Attachments
- Updated readings
- Final asset status
- Close action

## 10. Closed Work Orders

Historical work order list.

Required elements:

- WO number
- Asset
- Type: PM or CM
- Closed date
- Final status
- Parts used summary
- Link to details

## 11. Parts Reference

ERP-linked parts list.

Required elements:

- Search
- Filters
- ERP part code
- Part name
- Unit of measure
- Status
- Link to part detail

## 12. Part Detail

Required sections:

- ERP reference data
- Attachments
- Related work order usage, optional

## 13. PM Rules

Required elements:

- Rule list
- Asset
- Reading type
- Interval
- Last triggered
- Next due estimate
- Active/inactive

PM Rule removal is presented as `Deactivate`, not `Delete`. Inactive rules
remain visible in filtered history and can be reactivated.

## 14. Administration

Required screens:

- Employee Directory Import
- Users
- Fixed role reference and user role assignment; no custom role management
- Locations
- Master Data
- ERP Sync Settings
- Sync History

Employee Directory Import shows locally imported SharePoint employees and lets
an Administrator explicitly provision selected employees as ATMS users with one
fixed role. Imported employees are not users by default.
