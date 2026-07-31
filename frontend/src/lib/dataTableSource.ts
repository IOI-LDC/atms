import api from '@/lib/api'
import type { CursorPage } from '@/types'

/**
 * Page size sent on every cursor request. The backend enforces its own cap
 * (currently 100 for most endpoints). Sending a large value means the backend
 * applies its cap and we follow the cursor until next_cursor is null, so all
 * records are fetched regardless of the per-endpoint server limit.
 */
export const FETCH_LIMIT = 5000

/** A fixed select-filter option (value + human label). */
export interface FilterOption {
  value: string
  label: string
}

/**
 * Fetch the complete list for an endpoint by following cursor pagination until
 * next_cursor is null (client-mode data source). Sorting, filtering, and search
 * happen in the browser via TanStack Table (AppDataTable) after the full set is
 * loaded.
 *
 * The backend caps per_page per endpoint (e.g. 100 for assets). This function
 * transparently walks all pages so callers always receive the full result set.
 * `params` carry fixed tab semantics (e.g. { status: 'pending_review' }).
 */
export async function fetchList<T>(
  endpoint: string,
  params: Record<string, string | number | boolean> = {},
): Promise<T[]> {
  const results: T[] = []
  let cursor: string | null = null

  do {
    const query: Record<string, string | number | boolean> = {
      ...params,
      per_page: FETCH_LIMIT,
      ...(cursor ? { cursor } : {}),
    }
    const res = await api.get<CursorPage<T>>(endpoint, query)
    results.push(...res.data)
    cursor = res.meta?.next_cursor ?? null
  } while (cursor !== null)

  return results
}
