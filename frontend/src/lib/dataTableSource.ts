import api from '@/lib/api'
import type { CursorPage } from '@/types'
import type {
  ColumnFilter,
  ServerDataOptions,
  ServerFetchParams,
  ServerFetchResult,
  SortState,
} from '@ioi-dev/vue-table'

export interface CursorSourceOptions {
  /** API path, e.g. '/maintenance-requests' or '/work-orders'. */
  endpoint: string
  /** Always-sent query params (tab semantics, e.g. { status: 'pending_review' }). */
  baseParams?: Record<string, string | number | boolean>
  /** Page size. Default 25 (backend caps at 100). */
  pageSize?: number
}

/**
 * Build a `ServerDataOptions<T>` whose `fetch` maps the ioi-vue-table server
 * contract onto our Laravel cursor-paginated endpoints. This is the SINGLE
 * lib<->backend translation surface (see design doc §6).
 *
 * Cursor note: `params.cursor` is undefined on the initial/refresh fetch and
 * populated only on `fetchMore()` (source-verified), so we omit it when absent.
 * Sort is single-field (backend is single-sort); filters are exact-match only.
 * `globalSearch` is deliberately ignored — no backend `q=` exists yet.
 */
export function createCursorSource<T>(
  options: CursorSourceOptions,
): ServerDataOptions<T> {
  const { endpoint, baseParams = {}, pageSize = 25 } = options

  async function fetch(params: ServerFetchParams): Promise<ServerFetchResult<T>> {
    const query: Record<string, string | number | boolean> = {
      ...baseParams,
      per_page: params.pageSize || pageSize,
    }

    // Cursor — only present on load-more.
    if (params.cursor) query.cursor = params.cursor

    // Sort — backend is single-sort; take the primary sort state.
    const primary: SortState | undefined = params.sort?.[0]
    if (primary) query.sort = `${primary.field}:${primary.direction}`

    // Filters — exact-match only. For our `select` filters the value is a
    // plain string; we send it straight through as a per-field query param.
    for (const f of params.filters) {
      const v = extractFilterValue(f.filter)
      if (v !== null && v !== '') query[f.field] = v
    }

    const res = await api.get<CursorPage<T>>(endpoint, query)

    return {
      rows: res.data,
      nextCursor: res.meta.next_cursor,
      hasMore: res.meta.next_cursor !== null,
      totalRows: res.data.length,
    }
  }

  return {
    fetch,
    cursorMode: true,
    initialPageSize: pageSize,
    debounceMs: 300,
  }
}

/** Pull a scalar backend value out of a typed ColumnFilter. */
function extractFilterValue(filter: ColumnFilter): string | number | null {
  switch (filter.type) {
    case 'text':
      return filter.value
    case 'number':
      // Range filters are deferred; our select filters never produce these.
      return filter.operator === 'between' ? null : (filter.value ?? null)
    case 'date':
      // Deferred — WO from/to belongs in a reports surface.
      return null
    default:
      return null
  }
}
