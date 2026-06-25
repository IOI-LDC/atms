// ── Enums / unions ────────────────────────────────────────────────────────────

export type RoleCode =
  | 'administrator'
  | 'maintenance_manager'
  | 'technician'
  | 'logistics'
  | 'requester'
  | 'viewer'

export type MaintenanceRequestStatus = 'pending_review' | 'rejected' | 'converted' | 'cancelled'
export type WorkOrderStatus          = 'open' | 'in_progress' | 'completed' | 'closed' | 'cancelled'
export type PmTriggerType            = 'date' | 'reading' | 'date_or_reading'
export type Priority                 = 'low' | 'medium' | 'high' | 'critical'
export type MrType                   = 'corrective' | 'preventive'
export type MeterReadingSource       = 'user' | 'manual'
export type ErpSyncStatus            = 'running' | 'success' | 'partial' | 'failed'
export type AssetMaintenanceStatus   = 'Active' | 'Inactive'
export type AssetKind                = 'asset' | 'package' | 'component'
export type AssetMaintenanceSubStatus =
  | 'Installed' | 'Ready'                             // Active sub-statuses (component/package)
  | 'LIH' | 'DBR' | 'Disposed' | 'Scrapped' | 'Other' // Inactive sub-statuses

// ── Shared fragments ──────────────────────────────────────────────────────────

export interface Role { id: number; code: RoleCode; name: string }

/** Minimal asset reference embedded in other resources. */
export interface AssetRef { id: number; name: string; erp_asset_code: string; operational_status?: string }

/** Minimal user reference. `email` only visible to Admin/Manager. */
export interface UserRef { id: number; name: string; email?: string }

export interface LocationRef { id: number; name: string; type?: string }

// ── Auth ──────────────────────────────────────────────────────────────────────

/** Returned by GET /api/auth/me and POST /api/auth/login as { user: User }. */
export interface User {
  id: number
  name: string
  email: string
  is_active: boolean
  activated_at: string | null
  emp_id: string | null
  employee_id: number | null
  role: Role
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
  parent_asset_id: number | null
  child_assets_count?: number
  current_location?: LocationRef | null
  erp_status?: string | null              // not for Requester
  erp_last_synced_at?: string | null      // not for Requester
  is_active?: boolean                     // Admin/Manager only
  erp_raw_data?: Record<string, unknown>  // Admin only
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
  reviewed_by?: UserRef | null            // Admin/Manager/Viewer
  rejection_reason?: string | null        // hidden from Logistics
  cancellation_reason?: string | null     // hidden from Logistics
  is_preventive?: boolean                 // Admin/Manager/Viewer
  triggered_by_date?: boolean             // Admin/Manager/Viewer
  triggered_by_reading?: boolean          // Admin/Manager/Viewer
  trigger_date?: string | null            // "YYYY-MM-DD"
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
  assigned_to?: UserRef | null      // Admin/Manager/Tech/Viewer
  assigned_by?: UserRef | null      // Admin/Manager only
  parts?: WorkOrderPart[]           // Admin/Manager/Tech/Viewer
  started_at?: string | null
  completed_at?: string | null
  completion_notes?: string | null
  closed_at?: string | null
  cancelled_at?: string | null
  cancellation_reason?: string | null
  has_attachments?: number          // Admin/Manager/Tech
  maintenance_request?: MaintenanceRequest | null
}

// ── PM Rules ──────────────────────────────────────────────────────────────────

export interface PmRule {
  id: number
  name: string
  description: string | null
  trigger_type: PmTriggerType
  is_active: boolean
  interval_days: number | null
  interval_reading: number | null
  last_triggered_date: string | null
  last_triggered_reading: number | null
  created_at: string
  asset: AssetRef
  created_by?: UserRef | null      // Admin/Manager only
  usage_reading_type_id?: number | null
}

// ── Attachments ───────────────────────────────────────────────────────────────

export interface Attachment {
  id: number
  file_name: string
  mime_type: string
  size_bytes: number
  description: string | null
  created_at: string
  download_url?: string            // absent for Viewer
  uploaded_by?: UserRef | null     // Admin/Manager only
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

export interface DashboardSummary {
  pending_maintenance_requests: number
  open_work_orders: number
  overdue_pm_rules: number
  recently_closed_work_orders: number
}

export interface DashboardData {
  summary: DashboardSummary
  pending_maintenance_requests?: MaintenanceRequest[]
  open_work_orders?: WorkOrder[]
  overdue_pm_rules?: PmRule[]
  recently_closed_work_orders?: WorkOrder[]
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

export interface MessageResponse { message: string }
export interface DataResponse<T> { message?: string; data: T }
