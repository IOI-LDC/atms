// Status class maps

export function mrStatusClass(s: string): string {
  const m: Record<string, string> = {
    pending_review: 'status-badge status-pending',
    converted: 'status-badge status-converted',
    rejected: 'status-badge status-rejected',
    cancelled: 'status-badge status-cancelled',
  }
  return m[s] ?? 'status-badge'
}

export function mrStatusLabel(s: string): string {
  const m: Record<string, string> = {
    pending_review: 'Pending Review',
    converted: 'Converted to Work Order',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
  }
  return m[s] ?? s
}

export function woStatusClass(s: string): string {
  const m: Record<string, string> = {
    open: 'status-badge status-open',
    in_progress: 'status-badge status-in-progress',
    completed: 'status-badge status-completed',
    closed: 'status-badge status-closed',
    cancelled: 'status-badge status-cancelled',
  }
  return m[s] ?? 'status-badge'
}

export function woStatusLabel(s: string): string {
  const m: Record<string, string> = {
    open: 'Open',
    in_progress: 'In Progress',
    completed: 'Completed',
    closed: 'Closed',
    cancelled: 'Cancelled',
  }
  return m[s] ?? s
}

export function priorityClass(p: string): string {
  const m: Record<string, string> = {
    critical: 'status-badge priority-critical',
    high: 'status-badge priority-high',
    medium: 'status-badge priority-medium',
    low: 'status-badge priority-low',
  }
  return m[p] ?? 'status-badge'
}

export function priorityLabel(p: string): string {
  return p.charAt(0).toUpperCase() + p.slice(1)
}

/** Priority picker label — appends the urgency hint on the critical option only. */
export function priorityPickerLabel(opt: { value: string; label: string }): string {
  return opt.value === 'critical' ? `${opt.label} — immediate attention required` : opt.label
}

export function mrTypeLabel(t: string): string {
  return t === 'preventive' ? 'Preventive' : 'Corrective'
}

/** Failure classification label (corrective MRs only). null = not yet reviewed. */
export function failureLabel(v: boolean | null | undefined): string {
  if (v === true) return 'Failure'
  if (v === false) return 'No failure'
  return 'Not yet classified'
}

export function failureClass(v: boolean | null | undefined): string {
  if (v === true) return 'status-badge status-failure'
  if (v === false) return 'status-badge status-no-failure'
  return 'status-badge status-unclassified'
}

export function operationalStatusLabel(s: string | null | undefined): string {
  if (!s) return '—'
  const m: Record<string, string> = {
    active: 'Active',
    under_maintenance: 'Under Maintenance',
    down: 'Down',
    inactive: 'Inactive',
  }
  return m[s] ?? s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

export function operationalStatusClass(s: string | null | undefined): string {
  const m: Record<string, string> = {
    // active/inactive → new .status-active / .status-inactive (added to style.css)
    active: 'status-badge status-active',
    inactive: 'status-badge status-inactive',
    // under_maintenance → reuse existing amber WO badge
    under_maintenance: 'status-badge status-in-progress',
    // down → reuse existing red priority badge
    down: 'status-badge priority-critical',
  }
  return m[s ?? ''] ?? 'status-badge'
}

export function assetMaintenanceStatusLabel(s: string | null | undefined): string {
  if (!s) return '—'
  const m: Record<string, string> = {
    enrolled: 'In maintenance program',
    withdrawn: 'Withdrawn',
  }
  return m[s] ?? s
}

export function assetMaintenanceStatusClass(s: string | null | undefined): string {
  // Reuses the same .status-active / .status-inactive added for operational status
  const m: Record<string, string> = {
    enrolled: 'status-badge status-active',
    withdrawn: 'status-badge status-inactive',
  }
  return m[s ?? ''] ?? 'status-badge'
}

export function assetMaintenanceSubStatusLabel(s: string | null | undefined): string {
  if (!s) return '—'
  const m: Record<string, string> = {
    installed: 'Installed',
    ready: 'Ready',
    lih: 'Lost in Hole',
    dbr: 'Damaged Beyond Repair',
    disposed: 'Disposed',
    scrapped: 'Scrapped',
    other: 'Other',
  }
  return m[s] ?? s
}

export function assetKindLabel(k: string | null | undefined): string {
  if (!k) return '—'
  const m: Record<string, string> = {
    asset: 'Asset',
    package: 'Package',
    component: 'Component',
  }
  return m[k] ?? k
}

export function assetKindClass(k: string | null | undefined): string {
  const m: Record<string, string> = {
    // asset → reuse existing blue WO-open badge
    asset: 'status-badge status-open',
    // package / component → new classes (no analog exists)
    package: 'status-badge badge-kind-package',
    component: 'status-badge badge-kind-component',
  }
  return m[k ?? ''] ?? 'status-badge'
}

/**
 * Friendly display labels for known ERP FA subclass codes ("Asset Class" in
 * the UI). The live fa_subclass_type_codes table (fetched via
 * useListOptions().loadFaSubclasses) is the source of truth for which codes
 * exist — this map only formats them for display. Codes without a curated
 * entry (e.g. a code the ERP adds later) fall back to the raw code.
 */
const FA_SUBCLASS_LABELS: Record<string, string> = {
  'MUD MOTOR': 'Mud Motor',
  'MWD/LWD': 'MWD/LWD',
  DHT: 'DHT',
  NMDC: 'NMDC',
  MACHEQ: 'Machinery & Equipment',
  WHIPSTOCK: 'Whipstock',
  JARS: 'Jars',
  WIRELINE: 'Wireline',
  'SHOCK SUBS': 'Shock Subs',
  COMPLETION: 'Completion',
  FURNOFF: 'Furnoff',
  RTM: 'RTM',
  GYRO: 'Gyro',
  ORGEXP: 'Org Exp',
  PROPPLT: 'Propplt',
  VEH: 'Vehicle',
  COMPPER: 'Completion Perforating',
  HOLEOPENER: 'Hole Opener',
}

export function faSubclassLabel(code: string): string {
  return FA_SUBCLASS_LABELS[code] ?? code
}

export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return iso.slice(0, 10)
}

/**
 * Company-timezone display default (backend `ATMS_COMPANY_TIMEZONE`, default
 * Africa/Tripoli). ISO-8601 UTC timestamps are rendered in this zone.
 */
const COMPANY_TIMEZONE = 'Africa/Tripoli'

/** ISO-8601 UTC → "01 Jul 2026, 10:00" in the company timezone. */
export function fmtDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: COMPANY_TIMEZONE,
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(d)
}

/** KPI days metric → "45d" / "1.5d"; null (no basis) → em dash. */
export function fmtKpiDays(days: number | null | undefined): string {
  if (days == null) return '—'
  return Number.isInteger(days) ? `${days}d` : `${days.toFixed(1)}d`
}

/** KPI hours metric → "6.0h" (<24h) / "2d 3h" (≥24h); null → em dash. */
export function fmtKpiHours(hours: number | null | undefined): string {
  if (hours == null) return '—'
  if (hours < 24) return `${hours.toFixed(1)}h`
  let d = Math.floor(hours / 24)
  let h = Math.round(hours % 24)
  if (h === 24) {
    d += 1
    h = 0
  }
  return h === 0 ? `${d}d` : `${d}d ${h}h`
}

/** KPI percentage → "33.3%"; null (no basis) → em dash. */
export function fmtKpiPercent(pct: number | null | undefined): string {
  if (pct == null) return '—'
  return `${pct}%`
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function locationTypeClass(type: string | null | undefined): string {
  const m: Record<string, string> = {
    workshop: 'location-type-badge location-type-workshop',
    workshop_yard: 'location-type-badge location-type-workshop_yard',
    yard: 'location-type-badge location-type-yard',
    well_site: 'location-type-badge location-type-well_site',
    rig: 'location-type-badge location-type-rig',
    building: 'location-type-badge location-type-building',
  }
  return m[type ?? ''] ?? 'location-type-badge location-type-building'
}

export function pmStatusLabel(s: string | null | undefined): string {
  const m: Record<string, string> = {
    ok: 'OK',
    soon: 'Soon',
    due: 'Due',
  }
  return m[s ?? ''] ?? '—'
}

export function pmStatusClass(s: string | null | undefined): string {
  const m: Record<string, string> = {
    ok: 'status-badge pm-status-ok',
    soon: 'status-badge pm-status-soon',
    due: 'status-badge pm-status-due',
  }
  return m[s ?? ''] ?? 'status-badge'
}

export function pmTriggerLabel(t: string | null | undefined): string {
  const m: Record<string, string> = {
    date: 'Calendar',
    reading: 'Usage',
    date_or_reading: 'Calendar or Usage',
  }
  return m[t ?? ''] ?? '—'
}

/** Prefers the date-based due point; falls back to the reading-based one. */
export function pmDueLabel(a: {
  next_due_date: string | null
  next_due_reading: number | null
  rule: { usage_reading_type?: { unit: string } | null }
}): string {
  if (a.next_due_date) return fmtDate(a.next_due_date)
  if (a.next_due_reading !== null) {
    const unit = a.rule.usage_reading_type?.unit
    return unit ? `${a.next_due_reading} ${unit}` : `${a.next_due_reading}`
  }
  return '—'
}

/** Maintenance level badge — L1–L4 get a colour; any other custom value is neutral. */
export function pmLevelClass(level: string | null | undefined): string {
  if (!level) return ''
  const m: Record<string, string> = {
    L1: 'status-badge pm-level-l1',
    L2: 'status-badge pm-level-l2',
    L3: 'status-badge pm-level-l3',
    L4: 'status-badge pm-level-l4',
  }
  return m[level] ?? 'status-badge pm-level-custom'
}

export function roleLabel(code: string): string {
  const m: Record<string, string> = {
    administrator: 'Administrator',
    maintenance_manager: 'Maintenance Manager',
    technician: 'Technician',
    logistics: 'Logistics',
    requester: 'Requester',
    service: 'Service',
  }
  return m[code] ?? code
}

export function roleClass(code: string): string {
  const m: Record<string, string> = {
    administrator: 'status-badge role-administrator',
    maintenance_manager: 'status-badge role-maintenance-manager',
    technician: 'status-badge role-technician',
    logistics: 'status-badge role-logistics',
    requester: 'status-badge role-requester',
  }
  return m[code] ?? 'status-badge'
}

export function userStatusLabel(user: { is_active: boolean; activated_at: string | null }): string {
  if (!user.is_active) return 'Inactive'
  if (!user.activated_at) return 'Pending Activation'
  return 'Active'
}

export function userStatusClass(user: { is_active: boolean; activated_at: string | null }): string {
  if (!user.is_active) return 'status-badge status-inactive'
  if (!user.activated_at) return 'status-badge status-activation-pending'
  return 'status-badge status-active'
}

export function locationTypeLabel(type: string | null | undefined): string {
  if (!type) return '—'
  const m: Record<string, string> = {
    workshop: 'Workshop',
    workshop_yard: 'Workshop Yard',
    yard: 'Yard',
    well_site: 'Well Site',
    rig: 'Rig',
    building: 'Building',
  }
  return m[type] ?? type.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

export function woFormFieldTypeLabel(t: string): string {
  const m: Record<string, string> = { boolean: 'Yes/No', numeric: 'Numeric', text: 'Text' }
  return m[t] ?? t
}

export function partStatusLabel(isActive: boolean): string {
  return isActive ? 'Active' : 'Inactive'
}

export function partStatusClass(isActive: boolean): string {
  return isActive ? 'status-badge status-active' : 'status-badge status-inactive'
}
