/**
 * Display helpers and filter option lists for the Audit Logs viewer.
 *
 * Two things here are non-obvious:
 *  1. `subject_type` arrives in two forms — morph aliases (`asset`, `part`,
 *     `maintenance_request`, `work_order`) for mapped models and fully-qualified
 *     class names (`App\Models\User`, `App\Models\PmRule`) for everything else.
 *     `subjectTypeLabel()` normalizes both to a human label.
 *  2. The `event` filter is a server-side LIKE `%value%`. Category options use a
 *     trailing dot (`work_order.`) — or a trailing underscore prefix (`api_`,
 *     `pm_`) — so they scope to that family only without bleeding into siblings
 *     (`work_order.` excludes `work_order_form.*` and `..._work_order_part`).
 *
 * The event list mirrors the backend `AuditLogger::log(...)` call sites — a mix
 * of dotted `domain.action` events and older snake_case `verb_noun` events (65
 * total as of 2026-07). Unknown/future events stay reachable via the free-text
 * "Event contains…" input.
 */

/** A single filterable option. */
export interface AuditFilterOption {
  value: string
  label: string
}

/** A labelled group of options for a grouped <Select>. */
export interface AuditEventGroup {
  label: string
  options: AuditFilterOption[]
}

function titleCaseWord(word: string): string {
  return word.charAt(0).toUpperCase() + word.slice(1)
}

/**
 * Normalize a raw `subject_type` (morph alias or FQCN) to a human label.
 * `maintenance_request` → "Maintenance Request"; `App\Models\PmRule` → "PM Rule".
 */
export function subjectTypeLabel(subjectType: string | null | undefined): string {
  if (!subjectType) {
    return '—'
  }

  // FQCN form: keep only the class basename (App\Models\PmRule → PmRule).
  let base = subjectType.includes('\\') ? (subjectType.split('\\').pop() ?? subjectType) : subjectType

  if (base.includes('_')) {
    // snake_case morph alias → title-cased words.
    base = base.split('_').map(titleCaseWord).join(' ')
  } else {
    // PascalCase class name → spaced words.
    base = base.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
  }

  // Acronym fixups.
  return base.replace(/\bPm\b/g, 'PM').replace(/\bErp\b/g, 'ERP')
}

/**
 * Category ("all X events") quick filters. Values carry a trailing dot (or `_`
 * prefix) so the server LIKE scopes to that family only.
 */
export const auditEventCategories: AuditFilterOption[] = [
  { value: 'asset.', label: 'All Asset events' },
  { value: 'maintenance_request.', label: 'All Maintenance Request events' },
  { value: 'work_order.', label: 'All Work Order events' },
  { value: 'work_order_form.', label: 'All WO Form events' },
  { value: 'form_template.', label: 'All Form Template events' },
  { value: 'pm_', label: 'All Preventive Maintenance events' },
  { value: 'meter_reading.', label: 'All Meter Reading events' },
  { value: 'attachment.', label: 'All Attachment events' },
  { value: 'part.', label: 'All Part events' },
  { value: 'sync_parts', label: 'All Parts Sync events' },
  { value: 'user.', label: 'All User events' },
  { value: 'auth.', label: 'All Auth events' },
  { value: 'api_', label: 'All API Access events' },
]

/** Build a group where every option's value equals its label. */
function literalGroup(label: string, events: string[]): AuditEventGroup {
  return { label, options: events.map((e) => ({ value: e, label: e })) }
}

/** All known event names grouped by domain. */
export const auditEventGroups: AuditEventGroup[] = [
  literalGroup('Asset', [
    'asset.created',
    'asset.updated',
    'asset.location_updated',
    'asset.status_updated',
  ]),
  literalGroup('Maintenance Request', [
    'maintenance_request.created',
    'maintenance_request.updated',
    'maintenance_request.approved',
    'maintenance_request.rejected',
    'maintenance_request.cancelled',
  ]),
  literalGroup('Work Order', [
    'work_order.assigned',
    'work_order.started',
    'work_order.completed',
    'work_order.closed',
    'work_order.cancelled',
    'work_order.updated',
  ]),
  literalGroup('Work Order — Parts & Closure', [
    'record_work_order_part',
    'delete_work_order_part',
    'close_work_order_update_mr_is_failure',
    'close_work_order_update_pm_assignment',
    'close_work_order_reset_pm_assignment',
  ]),
  literalGroup('Work Order Form', [
    'work_order_form.field_value_updated',
    'work_order_form.synced',
    'work_order_form.sync_deferred',
  ]),
  literalGroup('Form Template', [
    'form_template.created',
    'form_template.updated',
    'form_template.deactivated',
    'form_template.reactivated',
    'form_template.field_added',
    'form_template.field_updated',
    'form_template.field_deleted',
    'form_template.fields_reordered',
  ]),
  literalGroup('Preventive Maintenance', [
    'pm_rule.created',
    'pm_rule.updated',
    'deactivate_pm_rule',
    'reactivate_pm_rule',
    'pm_assignment.created',
    'deactivate_pm_assignment',
    'reactivate_pm_assignment',
    'evaluate_pm_rule',
    'create_pm_suppression',
    'deactivate_pm_assignment_clear_suppression',
  ]),
  literalGroup('Meter Reading', [
    'meter_reading.recorded',
    'meter_reading.updated',
    'meter_reading.confirmed',
    'meter_reading.deleted',
  ]),
  literalGroup('Attachment', ['attachment.uploaded', 'attachment.soft_deleted']),
  literalGroup('Part', ['part.updated']),
  literalGroup('Parts Sync', ['sync_parts_started', 'sync_parts_completed', 'sync_parts_failed']),
  literalGroup('User', [
    'user.activated',
    'user.deactivated',
    'user.reactivated',
    'user.updated',
    'user.password_changed',
    'user.password_reset',
    'user.password_reset_by_admin',
  ]),
  literalGroup('Authentication', ['auth.login', 'auth.login_failed', 'auth.logout']),
  literalGroup('API Access', [
    'api_client_created',
    'api_client_revoked',
    'api_token_issued',
    'api_token_issuance_failed',
  ]),
]

/**
 * Subject-type filter values (exact match). Combines the morph aliases and the
 * FQCNs observed as audit subjects. Labels are derived via `subjectTypeLabel`.
 */
export const auditSubjectTypes: string[] = [
  'asset',
  'maintenance_request',
  'work_order',
  'part',
  'App\\Models\\User',
  'App\\Models\\PmRule',
  'App\\Models\\AssetPmAssignment',
  'App\\Models\\AssetMeterReading',
  'App\\Models\\FormTemplate',
  'App\\Models\\FormTemplateField',
  'App\\Models\\WorkOrderForm',
  'App\\Models\\WorkOrderFormField',
  'App\\Models\\WorkOrderPart',
  'App\\Models\\Attachment',
]
