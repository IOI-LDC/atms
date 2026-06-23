// Status class maps

export function mrStatusClass(s: string): string {
  const m: Record<string, string> = {
    pending_review: 'status-badge status-pending',
    converted:      'status-badge status-converted',
    rejected:       'status-badge status-rejected',
    cancelled:      'status-badge status-cancelled',
  }
  return m[s] ?? 'status-badge'
}

export function mrStatusLabel(s: string): string {
  const m: Record<string, string> = {
    pending_review: 'Pending Review',
    converted:      'Converted to Work Order',
    rejected:       'Rejected',
    cancelled:      'Cancelled',
  }
  return m[s] ?? s
}

export function woStatusClass(s: string): string {
  const m: Record<string, string> = {
    open:        'status-badge status-open',
    in_progress: 'status-badge status-in-progress',
    completed:   'status-badge status-completed',
    closed:      'status-badge status-closed',
    cancelled:   'status-badge status-cancelled',
  }
  return m[s] ?? 'status-badge'
}

export function woStatusLabel(s: string): string {
  const m: Record<string, string> = {
    open:        'Open',
    in_progress: 'In Progress',
    completed:   'Completed',
    closed:      'Closed',
    cancelled:   'Cancelled',
  }
  return m[s] ?? s
}

export function priorityClass(p: string): string {
  const m: Record<string, string> = {
    critical: 'status-badge priority-critical',
    high:     'status-badge priority-high',
    medium:   'status-badge priority-medium',
    low:      'status-badge priority-low',
  }
  return m[p] ?? 'status-badge'
}

export function priorityLabel(p: string): string {
  return p.charAt(0).toUpperCase() + p.slice(1)
}

export function mrTypeLabel(t: string): string {
  return t === 'preventive' ? 'Preventive' : 'Corrective'
}

export function operationalStatusLabel(s: string | null | undefined): string {
  if (!s) return '—'
  const m: Record<string, string> = {
    active:            'Active',
    under_maintenance: 'Under Maintenance',
    down:              'Down',
    inactive:          'Inactive',
  }
  return m[s] ?? s.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}

export function fmtDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  return iso.slice(0, 10)
}

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
