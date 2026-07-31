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
- **DatePicker** — shadcn-vue date picker (`components/ui/date-picker`): a Calendar
  over reka-ui + `@internationalized/date`. v-model is a `yyyy-MM-dd` string; supports
  `min`/`max`/`clearable`. **The only date-input control** — native
  `<input type="date">`/`datetime-local` is banned in feature code.
- **Calendar** — shadcn-vue calendar primitive (`components/ui/calendar`), used by DatePicker.
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
  (read-only), new location (select, required), reason (text, optional), and
  notes (textarea, optional). Calls `POST /api/assets/{asset}/location`; the
  backend records the processing time as `effective_at`. Visible to Admin,
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
- **AuditLogsView** — Admin-only audit log viewer (`views/admin/AuditLogsView.vue`).
  The app's first **server-side cursor-paginated** list (not `AppDataTable`, which is
  client-mode): filter bar (event grouped-select + free-text LIKE, subject type, actor,
  date range with To ≥ From / no-future validation) and a "Load more" button. Backed by
  `useAuditLogs` (`load`/`loadMore`/`hasMore`) and `lib/auditColumns.ts`.
- **AuditLogDetailSheet** — Row-detail side sheet with side-by-side before/after JSON
  panes + metadata / IP / user agent / request id (`components/admin/AuditLogDetailSheet.vue`).

## Component Rules

- Interactive controls use shared shadcn-vue components.
- Feature Vue files use semantic CSS classes and contain no Tailwind utility
  classes.
- Dates display as `yyyy-MM-dd` and datetimes as `yyyy-MM-dd HH:mm:ss` via
  `fmtDate`/`fmtDateTime`; date inputs use the shadcn `DatePicker`, never a native
  date input.
- Create and edit forms use sheets.
- Confirmation and short consequential actions use dialogs.
- Complex review and execution workflows use full pages.
- Every user-initiated persistent change passes through
  `ConfirmChangeDialog`, including profile and settings edits.
- Backend authorization remains authoritative even when unavailable actions are
  hidden from the interface.

See `docs/02-design/UI_DESIGN_SYSTEM.md` for the complete standard.
