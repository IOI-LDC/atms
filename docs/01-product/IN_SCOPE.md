# In Scope

## 1. ERP Fixed Assets and Parts Integration

The system will read fixed assets and parts from the existing ERP. ERP will remain the master source for fixed assets and parts. ATMS may keep a local synchronized copy to support maintenance workflows, history, search, and reporting.

## 2. Local Operational Asset Registry

The system will maintain a local operational copy of ERP fixed assets. Additional operational information can be stored against each asset, such as current physical location, usage readings, maintenance status, attachments, and maintenance history.

## 3. Parts Reference Database

Parts will be linked from the ERP parts master. The system will allow users to select ERP-linked parts when recording parts used on a work order. The system will not manage procurement or full warehouse operations.

## 4. Asset Usage Tracking

Each asset can have usage readings such as operating hours, kilometers, or other usage/meter values. These readings will be used to support preventive maintenance triggering and asset maintenance history.

Requesters may submit a reading as supporting information when creating a
Corrective Maintenance Request or from an asset record. Requester-submitted
readings are unverified until confirmed by an Administrator, Maintenance
Manager, or Technician. Only confirmed readings update the asset's current
meter value and participate in preventive maintenance calculations.

## 5. Physical Location Tracking

The system will track the current physical location of each asset, such as warehouse, yard, maintenance yard, workshop, or wellsite. Location changes will be recorded as history so users can see where an asset has been over time.

## 6. Preventive Maintenance Rules

The system will allow preventive maintenance rules to be configured for assets. These rules may be based on time intervals, operating hours, kilometers, or other usage readings.

## 7. Automatic Preventive Maintenance Requests

When preventive maintenance criteria are met, the system will automatically generate a preventive maintenance request. The request will then follow the standard approval process before becoming a work order.

## 8. Corrective Maintenance Requests

Users will be able to raise corrective maintenance requests when an asset is faulty, damaged, underperforming, or requires repair. The request will include the asset, issue description, priority, location, and supporting notes where required.

## 9. Maintenance Request Approval

All maintenance requests, whether preventive or corrective, will be reviewed by the Maintenance Manager. The Maintenance Manager can approve or reject the request. Approved requests will be converted into work orders.

## 10. Work Order Management

Once a maintenance request is approved, the system will create a work order. The work order will be assigned, executed, updated, and closed through a simple workflow.

## 11. Parts Usage on Work Orders

Users will be able to record parts used during maintenance. Parts will be selected from the ERP-linked parts list, and the quantity used will be recorded against the work order.

## 12. Work Order Closure

After maintenance work is completed, the work order will be closed. Closure will capture final work notes, parts used, updated asset readings where applicable, and final asset condition/status.

## 13. Asset and Parts Attachments

The system will support attachments against both assets and parts. These may include user manuals, maintenance instructions, datasheets, safety instructions, calibration certificates, warranty documents, photos, or other technical references.

## 14. Maintenance History

Each asset will have a maintenance history view showing preventive and
corrective Maintenance Requests, Work Orders, parts used, usage readings,
location changes, attachments, and closure notes. The view is derived from
those authoritative source records rather than copied into a duplicate history
table.

## 15. Dashboard and Basic Reporting

The system will include a basic operational dashboard showing pending maintenance requests, open work orders, overdue preventive maintenance, recently closed work orders, and assets due for maintenance.

## 16. User Roles and Access Control

The system will include six fixed user roles: Administrator, Maintenance
Manager, Technician, Logistics, Requester/User, and Viewer. Access will be
controlled based on the user's single assigned role.

Administrators may import employees from the client's SharePoint List into a
local employee directory. Importing an employee does not grant ATMS access.
Administrators explicitly select which employees become ATMS users, assign one
fixed role, and send an activation link so each user sets their own password.

## 17. System Settings

The system will include settings for managing users, locations, asset usage reading types, preventive maintenance rules, ERP sync configuration, and dropdown/master-data items used across the system.

## 18. Dropdown / Master Data Management

Administrators will be able to manage configurable dropdown values used in the system, such as locations, asset statuses, maintenance priorities, usage reading types, work order statuses, maintenance categories, and other lookup values required for the workflow.
