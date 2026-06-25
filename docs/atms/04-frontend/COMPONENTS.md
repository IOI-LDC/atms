# Frontend Component Plan

## Shared Components

- AppLayout
- SidebarNavigation
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
- UpdateLocationSheet
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
