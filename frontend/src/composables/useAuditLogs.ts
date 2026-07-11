import { ref, computed } from 'vue'
import api from '@/lib/api'
import type { AuditLog, CursorPage, User } from '@/types'

/** Server-side filters accepted by GET /api/admin/audit-logs. */
export interface AuditLogFilters {
  /** Event name — partial match (LIKE) server-side. */
  event?: string
  /** Actor user id — exact match. */
  user_id?: string
  /** Subject type — exact match (morph alias or FQCN). */
  subject_type?: string
  /** Subject id — exact match. */
  subject_id?: string
  /** created_at >= (ISO date, inclusive). */
  from?: string
  /** created_at <= (ISO date, inclusive). */
  to?: string
}

/** Page size per request. Backend default is 50, capped at 500. */
const PER_PAGE = 50

/**
 * Server-side, cursor-paginated audit-log list. Unlike every other list in the
 * app, this must NOT use `fetchList` — audit logs grow unbounded and that helper
 * walks every cursor page into memory. Here we hold one page at a time and append
 * on demand via `loadMore()`.
 */
export function useAuditLogs() {
  const rows = ref<AuditLog[]>([])
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)

  const nextCursor = ref<string | null>(null)
  const hasMore = computed(() => nextCursor.value !== null)

  // Snapshot of the filters the current result set was fetched with, so
  // `loadMore()` follows the same query.
  let activeFilters: AuditLogFilters = {}

  function buildQuery(cursor: string | null): Record<string, string | number> {
    // Empty/undefined values are stripped by the api client's buildUrl.
    const query: Record<string, string | number> = {
      per_page: PER_PAGE,
      ...(activeFilters.event ? { event: activeFilters.event } : {}),
      ...(activeFilters.user_id ? { user_id: activeFilters.user_id } : {}),
      ...(activeFilters.subject_type ? { subject_type: activeFilters.subject_type } : {}),
      ...(activeFilters.subject_id ? { subject_id: activeFilters.subject_id } : {}),
      ...(activeFilters.from ? { from: activeFilters.from } : {}),
      ...(activeFilters.to ? { to: activeFilters.to } : {}),
    }
    if (cursor) {
      query.cursor = cursor
    }
    return query
  }

  /** Load page one for the given filters, replacing any existing rows. */
  async function load(filters: AuditLogFilters = {}): Promise<void> {
    activeFilters = { ...filters }
    loading.value = true
    error.value = null
    try {
      const res = await api.get<CursorPage<AuditLog>>('/admin/audit-logs', buildQuery(null))
      rows.value = res.data ?? []
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      rows.value = []
      nextCursor.value = null
      error.value = 'Failed to load audit logs.'
    } finally {
      loading.value = false
    }
  }

  /** Append the next cursor page to the current rows. */
  async function loadMore(): Promise<void> {
    if (!nextCursor.value || loadingMore.value) {
      return
    }
    loadingMore.value = true
    error.value = null
    try {
      const res = await api.get<CursorPage<AuditLog>>('/admin/audit-logs', buildQuery(nextCursor.value))
      rows.value = [...rows.value, ...(res.data ?? [])]
      nextCursor.value = res.meta?.next_cursor ?? null
    } catch {
      error.value = 'Failed to load more audit logs.'
    } finally {
      loadingMore.value = false
    }
  }

  // ── Actor filter options ─────────────────────────────────────────────────
  // Populated from the full user list (admin-only endpoint, already used by
  // UsersView) rather than derived from loaded rows, so the picker is complete
  // and stable regardless of how many pages are loaded.
  const actors = ref<{ id: number; name: string }[]>([])

  async function loadActors(): Promise<void> {
    if (actors.value.length > 0) {
      return
    }
    try {
      const res = await api.get<{ data: User[] }>('/admin/users')
      actors.value = (res.data ?? [])
        .map((u) => ({ id: u.id, name: u.name }))
        .sort((a, b) => a.name.localeCompare(b.name))
    } catch {
      actors.value = []
    }
  }

  return {
    rows,
    loading,
    loadingMore,
    error,
    hasMore,
    load,
    loadMore,
    actors,
    loadActors,
  }
}
