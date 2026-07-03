// ── Enums / unions ────────────────────────────────────────────────────────────

export type RoleCode =
  | 'administrator'
  | 'maintenance_manager'
  | 'technician'
  | 'logistics'
  | 'requester'
  | 'service'

export type MaintenanceRequestStatus = 'pending_review' | 'rejected' | 'converted' | 'cancelled'
export type WorkOrderStatus = 'open' | 'in_progress' | 'completed' | 'closed' | 'cancelled'
export type PmTriggerType = 'date' | 'reading' | 'date_or_reading'
export type Priority = 'low' | 'medium' | 'high' | 'critical'
export type MrType = 'corrective' | 'preventive'
export type MeterReadingSource = 'user' | 'manual'
export type ErpSyncStatus = 'running' | 'success' | 'partial' | 'failed'
export type AssetMaintenanceStatus = 'enrolled' | 'withdrawn'
export type AssetKind = 'asset' | 'package' | 'component'
export type AssetMaintenanceSubStatus =
  | 'installed'
  | 'ready' // enrolled sub-statuses (component/package)
  | 'lih'
  | 'dbr'
  | 'disposed'
  | 'scrapped'
  | 'other' // withdrawn sub-statuses
export type WoFormFieldType = 'boolean' | 'numeric' | 'text'

// ── Shared fragments ──────────────────────────────────────────────────────────

export interface Role {
  id: number
  code: RoleCode
  name: string
  description?: string
}

/** Minimal asset reference embedded in other resources. */
export interface AssetRef {
  id: number
  name: string
  erp_asset_code: string
  operational_status?: string
}

/** Minimal user reference. `email` only visible to Admin/Manager. */
export interface UserRef {
  id: number
  name: string
  email?: string
}

/** A Work Order assignee candidate (active Technician or Maintenance Manager). */
export interface Assignee {
  id: number
  name: string
  role: string
}

export interface LocationRef {
  id: number
  name: string
  type?: string
}

// ── Auth ──────────────────────────────────────────────────────────────────────

/** Returned by GET /api/auth/me and POST /api/auth/login as { user: User }. */
export interface User {
  id: number
  name: string
  email: string
  is_active: boolean
  activated_at: string | null
  email_verified_at: string | null
  emp_id: string | null
  employee_id: number | null
  role: Role
  created_at: string
  updated_at: string
}

// ── Assets ────────────────────────────────────────────────────────────────────

export interface Asset {
  id: number
  erp_asset_code: string
  /** ERP FA subclass code — the authoritative asset classification (e.g. "MUD MOTOR", "MWD/LWD"). Read-only. */
  fa_subclass_code: string | null
  name: string
  description: string | null
  serial_number: string | null
  model: string | null
  manufacturer: string | null
  operational_status: string | null
  maintenance_status: AssetMaintenanceStatus | null
  maintenance_sub_status: AssetMaintenanceSubStatus | null
  asset_kind: AssetKind | null
  asset_tag: string | null
  is_booked?: boolean // availability marker — reserved for a Job/Project
  parent_asset_id: number | null
  child_assets_count?: number
  current_location?: LocationRef | null
  erp_status?: string | null // not for Requester
  erp_last_synced_at?: string | null // not for Requester
  is_active?: boolean // Admin/Manager only
  erp_raw_data?: Record<string, unknown> // Admin only
  created_at: string
  updated_at: string
}

export interface AssetMeterReading {
  id: number
  asset_id: number
  usage_reading_type_id: number
  reading_value: string
  reading_at: string
  source: MeterReadingSource
  entered_by_user_id: number
  confirmed_by_user_id: number | null
  confirmed_at: string | null
  notes: string | null
}

export interface AssetLocationHistoryItem {
  id: number
  asset_id: number
  from_location_id: number | null
  to_location_id: number
  from_location?: LocationRef | null
  to_location?: LocationRef | null
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
}

/** Derived read-model — no table, assembled from WO/MR history. */
export interface MaintenanceHistoryItem {
  date: string | null
  type: MrType | null
  work_order_number: string
  maintenance_request_number: string | null
  description: string | null
  priority: Priority
  parts_used?: { part_name: string | null; quantity: number }[]
  closed_at: string | null
}

// ── Parts ─────────────────────────────────────────────────────────────────────

export interface Part {
  id: number
  erp_part_code: string
  name: string
  description: string | null
  unit_of_measure: string | null
  category: string | null
  is_active: boolean
  erp_status?: string | null
  erp_last_synced_at?: string | null
  erp_raw_data?: Record<string, unknown>
  created_at: string
}

// ── Maintenance Requests ──────────────────────────────────────────────────────

export interface MaintenanceRequest {
  id: number
  number: string
  type: MrType
  status: MaintenanceRequestStatus
  priority: Priority
  description: string | null
  created_at: string
  asset: AssetRef
  created_by?: UserRef | null
  reviewed_by?: UserRef | null // Admin/Manager/Viewer
  rejection_reason?: string | null // hidden from Logistics
  cancellation_reason?: string | null // hidden from Logistics
  is_preventive?: boolean // Admin/Manager/Viewer
  triggered_by_date?: boolean // Admin/Manager/Viewer
  triggered_by_reading?: boolean // Admin/Manager/Viewer
  trigger_date?: string | null // "YYYY-MM-DD"
  trigger_reading_value?: string | null
  work_order?: { id: number; number: string; status: WorkOrderStatus } | null
  has_attachments?: number
}

// ── Work Orders ───────────────────────────────────────────────────────────────

export interface WorkOrderPart {
  id: number
  part: { id: number; name: string; erp_part_code: string; unit_of_measure: string | null }
  quantity: number
  notes: string | null
}

export interface WorkOrder {
  id: number
  number: string
  status: WorkOrderStatus
  priority: Priority
  description: string | null
  asset: AssetRef
  created_at: string
  assigned_to?: UserRef | null // Admin/Manager/Tech/Viewer
  assigned_by?: UserRef | null // Admin/Manager only
  parts?: WorkOrderPart[] // Admin/Manager/Tech/Viewer
  started_at?: string | null
  completed_at?: string | null
  completion_notes?: string | null
  closed_at?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  has_attachments?: number // Admin/Manager/Tech
  maintenance_request?: MaintenanceRequest | null
  form?: WoFormInstance | null // Admin/Manager/Tech — present when the WO has an attached form
}

// ── WO Forms ──────────────────────────────────────────────────────────────────

export interface WoFormTemplateField {
  id: number
  uuid: string
  label: string
  field_type: WoFormFieldType
  has_pre_post: boolean
  unit: string | null
  is_required: boolean
  sort_order: number
}

export interface WoFormTemplate {
  id: number
  name: string
  fa_subclass_code: string
  is_active: boolean
  fields?: WoFormTemplateField[]
  fields_count?: number
  created_at: string
}

/** Self-contained snapshot field — copies template metadata plus captured values. */
export interface WoFormFieldValue {
  id: number
  uuid: string
  label: string
  field_type: WoFormFieldType
  has_pre_post: boolean
  unit: string | null
  is_required: boolean
  sort_order: number
  pre_value: string | null
  post_value: string | null
  notes: string | null
}

export interface WoFormInstance {
  id: number
  form_template_id: number | null
  snapshotted_at: string
  template_is_stale?: boolean
  sync_dismissed_at?: string | null
  fields: WoFormFieldValue[]
}

/** 422 completion-gate payload entry — one per unfilled required field/slot. */
export interface MissingField {
  uuid: string
  label: string
  missing: ('pre' | 'post')[]
}

// ── PM Rules ──────────────────────────────────────────────────────────────────

export type PmStatus = 'ok' | 'soon' | 'due'

export interface PmSuppression {
  id: number
  decision_type?: string | null
  suppressed_until_date: string | null
  suppressed_until_reading: number | null
  source_mr_id: number | null
}

export interface PmRule {
  id: number
  name: string
  maintenance_level: string | null
  description: string | null
  trigger_type: PmTriggerType
  is_active: boolean
  interval_days: number | null
  interval_reading: number | null
  assignments_count?: number
  created_at: string
  updated_at?: string
  usage_reading_type?: { id: number; name: string; unit: string } | null
  assignments?: AssetPmAssignment[]
  created_by?: UserRef | null // Admin/Manager only
}

/** Nested rule template inside an assignment. */
export interface PmAssignmentRule {
  id: number
  name: string
  maintenance_level: string | null
  trigger_type: PmTriggerType
  interval_days: number | null
  interval_reading: number | null
  usage_reading_type?: { id: number; name: string; unit: string } | null
}

export interface AssetPmAssignment {
  id: number
  asset_id: number
  pm_rule_id: number
  is_active: boolean
  asset?: AssetRef
  last_triggered_date: string | null
  last_triggered_reading: number | null
  next_due_date: string | null
  next_due_reading: number | null
  progress_percentage: number | null
  pm_status: PmStatus
  rule: PmAssignmentRule
  assigned_by?: UserRef | null
  assigned_at?: string
  suppressions?: PmSuppression[]
}

// ── Attachments ───────────────────────────────────────────────────────────────

export interface Attachment {
  id: number
  file_name: string
  mime_type: string
  size_bytes: number
  description: string | null
  created_at: string
  download_url?: string // absent for Viewer
  uploaded_by?: UserRef | null // Admin/Manager only
  can_delete?: boolean // policy-driven (AttachmentResource); true when the current user may delete
}

// ── Admin resources ───────────────────────────────────────────────────────────

export interface Employee {
  id: number
  name: string
  emp_id: string | null
  email?: string | null
  department?: string | null
  job_title?: string | null
  user?: { id: number; name: string; role: Role } | null
}

export interface Location {
  id: number
  parent_id: number | null
  name: string
  type: string
  code: string | null
  description: string | null
  is_active: boolean
}

export interface MasterDataItem {
  id: number
  group_key: string
  value: string
  label: string
  sort_order: number | null
  is_active: boolean
}

export interface UsageReadingType {
  id: number
  name: string
  unit: string
  is_active: boolean
}

export interface FaSubclassTypeCode {
  id: number
  fa_subclass_code: string
  type_code: string
  description: string | null
  has_no_physical_size: boolean
}

export interface ErpSyncJob {
  id: number
  sync_type: 'assets' | 'parts'
  status: ErpSyncStatus
  total_records: number
  created_count: number
  updated_count: number
  failed_count: number
  error_message: string | null
  started_at: string
  completed_at: string | null
}

export interface AuditLog {
  id: number
  event: string
  actor?: UserRef | null
  subject_type?: string | null
  subject_id?: number | null
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
  ip_address?: string | null
  created_at: string
}

export interface CompanySettings {
  timezone: string
}

// ── Dashboard ─────────────────────────────────────────────────────────────────

// Every field is optional: the backend omits a key entirely when the current
// user's role isn't permitted to see it — absence (not zero/null) is the gate.
export interface DashboardSummary {
  pending_maintenance_requests?: number
  open_work_orders?: number
  overdue_pm_assignments?: number
  recently_closed_work_orders?: number
}

export interface DashboardData {
  summary: DashboardSummary
  pending_maintenance_requests?: MaintenanceRequest[]
  open_work_orders?: WorkOrder[]
  overdue_pm_assignments?: AssetPmAssignment[]
  recently_closed_work_orders?: WorkOrder[]
}

// ── Dashboard KPIs (GET /api/dashboard/kpis) ──────────────────────────────────
// Full payload to every role (not role-filtered), over a rolling 90-day window.
// Several scalars are `null` when there is no data in the window ("no basis to
// compute" — render an em dash, never 0). See DASHBOARD_KPI_HANDOFF.md §4.

export interface RelocatedAssetItem {
  id: number
  asset_id: number
  asset: { id: number; name: string; erp_asset_code: string; asset_tag: string }
  // `from_location` is null for an asset's first-ever placement (no prior
  // location). Verified against the live endpoint — the handoff type omits this.
  from_location: { id: number; name: string } | null
  to_location: { id: number; name: string } | null
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
  created_at: string
}

export interface DashboardKpiResponse {
  window: { days: number; from: string; to: string } // ISO 8601 bounds (UTC)
  kpis: {
    mtbf: { days: number | null }
    failure_rate: { failures: number; per_day: number }
    mttr: { hours: number | null }
    pm_compliance: { compliant: number; total: number; percentage: number | null }
    avg_mr_duration: { hours: number | null }
    avg_wo_duration: { hours: number | null }
  }
  recently_relocated_assets: RelocatedAssetItem[]
}

// ── Pagination ────────────────────────────────────────────────────────────────

export interface CursorMeta {
  path: string
  per_page: number
  next_cursor: string | null
  prev_cursor: string | null
}

export interface CursorLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface CursorPage<T> {
  data: T[]
  links: CursorLinks
  meta: CursorMeta
}

// ── Mutation response wrappers ────────────────────────────────────────────────

export interface MessageResponse {
  message: string
}
export interface DataResponse<T> {
  message?: string
  data: T
}
