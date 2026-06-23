# In Scope

## 1. ERP Parts Integration + Manual Asset Management

The system will read parts from the existing ERP. ERP remains the master source for parts. ATMS keeps a local synchronized copy of parts to support maintenance workflows, history, search, and reporting.

Assets are managed manually within ATMS. Administrators and Maintenance Managers may create, update, and manage asset records directly. There is no ERP asset source for this client deployment.

## 2. Manual Asset Registry

The system provides a full asset registry managed manually by Administrators and Maintenance Managers. Assets can be created, updated, and soft-deactivated. Operational information stored against each asset includes name, description, category, serial number, model, manufacturer, operational status, current physical location, usage readings, maintenance status, attachments, and maintenance history.

## 3. Parts Reference Database

Parts will be linked from the ERP parts master. The system will allow users to select ERP-linked parts when recording parts used on a work order. The system will not manage procurement or full warehouse operations.

Parts used on Work Orders are operational usage records only. The MVP does not
maintain stock-on-hand quantities, stock valuation, procurement, warehouse
transactions, or parts costs.

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

Each MVP PM Rule belongs to one individual asset. Category-level,
asset-type-level, unit/package-level, group, and template rules are excluded.

## 6a. Asset Creation and Management

Administrators and Maintenance Managers may create asset records manually
through the Asset Registry. Asset fields include name, description, category,
serial number, model, manufacturer, and operational status. Assets may be
updated at any time by Admin/Manager. The current location may be updated
through the asset edit screen, which records location history on change. Assets
are soft-deactivated (is_active = false) rather than deleted.

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

## 17. Account Email Delivery

Production activation and password-reset emails will be delivered through
Microsoft Power Automate. These security emails are the only external email
notifications included in MVP.

## 18. System Settings

The system will include settings for managing users, locations, asset usage reading types, preventive maintenance rules, ERP sync configuration, and dropdown/master-data items used across the system.

## 19. Dropdown / Master Data Management

Administrators will be able to manage configurable dropdown values used in the system, such as locations, asset statuses, maintenance priorities, usage reading types, work order statuses, maintenance categories, and other lookup values required for the workflow.

## 20. SharePoint Portal Link

The company's SharePoint portal may contain a normal link that opens the
separately hosted ATMS web application. The SharePoint portal can remain
available to all internal users, but portal access does not grant ATMS access.
ATMS continues to require its own Laravel login and fixed-role authorization.
