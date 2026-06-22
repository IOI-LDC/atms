import api from '@/lib/api'
import type { CursorPage } from '@/types'

/**
 * Max rows fetched per list in one request. The backend caps `per_page` at
 * 5000 (see the index queries); client-mode tables fetch their whole list in a
 * single request and then sort/filter/search in memory. ATMS list volumes
 * (low thousands over the deployment's lifetime) stay well under this.
 */
export const FETCH_LIMIT = 5000

/** A fixed select-filter option (value + human label). */
export interface FilterOption {
  value: string
  label: string
}

/**
 * Fetch a full list in one request (client-mode data source). Sorting, filtering
 * and search then happen in the browser via the ioi-vue-table. `params` carry
 * fixed tab semantics (e.g. { status: 'pending_review' }).
 */
export async function fetchList<T>(
  endpoint: string,
  params: Record<string, string | number | boolean> = {},
): Promise<T[]> {
  const res = await api.get<CursorPage<T>>(endpoint, { ...params, per_page: FETCH_LIMIT })
  return res.data
}
