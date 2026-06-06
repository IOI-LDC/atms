# Frontend Routes

## Dashboard

```text
/dashboard
```

## Assets

```text
/assets
/assets/:assetId
/assets/:assetId/readings
/assets/:assetId/location-history
/assets/:assetId/maintenance-history
/assets/:assetId/attachments
```

These can be implemented as tabs within `/assets/:assetId` rather than separate full pages.

## Work Orders Module

```text
/work-orders
/work-orders?tab=pending-requests
/work-orders?tab=active
/work-orders?tab=closed
/work-orders/requests/:requestId
/work-orders/:workOrderId
```

## Parts Reference

```text
/parts
/parts/:partId
```

## PM Rules

```text
/pm-rules
/pm-rules/create
/pm-rules/:ruleId
/pm-rules/:ruleId/edit
```

## Administration

```text
/admin/employees
/admin/users
/admin/roles
/admin/locations
/admin/master-data
/admin/erp-sync
/admin/company-settings
```

`/admin/roles` is a read-only reference for the six fixed roles. Role
assignment is performed while creating or editing a user. The MVP must not
provide custom role or permission management.

`/admin/employees` is the SharePoint-imported employee directory. Administrator
selects an employee, assigns one fixed role, provisions the account, and sends
an activation link. The Administrator never enters the user's password.
