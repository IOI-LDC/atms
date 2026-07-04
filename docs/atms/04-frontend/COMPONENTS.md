# Frontend Component Plan

## Shared Components

- **AppLayout** — application shell (sidebar + header bar + main content)
- **AppSidebar** — role-aware flat navigation sidebar with ATMS logo header
- **AppUserMenu** — user dropdown in the header bar (avatar, name, role, email, sign out)
- **KpiTile** — compact stat tile (icon + title + value + subtitle), optionally a router link
- **AppDataTable** — reusable data table wrapper
- **AssetCombobox** — asset search/select combobox
- **PartCombobox** — part search/select combobox
- PageHeader
- DataTable
- SearchInput
- FilterBar
- StatusBadge
- EmptyState
- ConfirmDialog
- FormSheet
- ConfirmChangeDialog
- AttachmentUploader
- AttachmentList
- DetailCard
- ActivityTimeline
- FormSection
- SelectField
- DateField
- NumberField

## Domain Components

### Assets

- AssetTable
- AssetSummaryCard
- AssetStatusBadge
- MeterReadingList
- AddMeterReadingSheet
- LocationHistoryList
- AssetAttachmentsPanel
- AssetMaintenanceHistory

### Work Orders

- MaintenanceRequestTable
- MaintenanceRequestReviewPanel
- WorkOrderTable
- WorkOrderStatusBadge
- WorkOrderPartsUsed
- CloseWorkOrderDialog
- WorkOrderActivityHistory

### Parts

- PartsTable
- PartDetailCard
- PartAttachmentsPanel
- PartRequestForm

### Locations

- **UpdateLocationSheet** — Side sheet for updating an asset's physical
  location. Fields: asset (read-only, pre-populated), current location
  (read-only), new location (select, required), effective date (datetime,
  defaults to now, required), reason (text, optional), notes (textarea,
  optional). Calls `POST /api/assets/{asset}/location`. Visible to Admin,
  Manager, Logistics. Follows the standard confirm-then-submit pattern.
- **LocationList** — Data table for the "Manage Locations" tab. Columns: name,
  type, code, parent location, active status, created date. Row actions:
  Edit, Activate/Deactivate. Admin only.
- **LocationForm** — Side sheet for creating/editing a location definition.
  Fields: name (required), type (required), code (optional), parent location
  (optional select), description (optional). Calls `POST/PATCH
  /api/admin/locations`. Admin only.

### Admin

- UserTable
- UserForm
- LocationTreeOrTable
- MasterDataGroupEditor
- PmRuleTable
- PmRuleForm
- PmDueStatusBadge

### Settings

- ErpSyncHistoryTable
- AuditLogTable

## Component Rules

- Interactive controls use shared shadcn-vue components.
- Feature Vue files use semantic CSS classes and contain no Tailwind utility
  classes.
- Create and edit forms use sheets.
- Confirmation and short consequential actions use dialogs.
- Complex review and execution workflows use full pages.
- Every user-initiated persistent change passes through
  `ConfirmChangeDialog`, including profile and settings edits.
- Backend authorization remains authoritative even when unavailable actions are
  hidden from the interface.

See `docs/02-design/UI_DESIGN_SYSTEM.md` for the complete standard.
