<script lang="ts" generic="TRow">
import type { SortingState, ColumnFiltersState } from '@tanstack/vue-table'

/**
 * Persisted view state for a single table instance, keyed by route path + label
 * (see {@link AppDataTable}). Captured continuously and restored on next visit
 * so users keep their sort / filters / search / page-size across navigation.
 */
interface TableSnapshot {
  globalFilter: string
  sorting: SortingState
  columnFilters: ColumnFiltersState
  pageSize: number
}

// Module-level so it survives component unmount/remount on route changes within
// a session. Keyed the same way as the component's `stateKey`.
const tableStateCache = new Map<string, TableSnapshot>()
</script>

<script setup lang="ts" generic="TRow">
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import {
  useVueTable,
  getCoreRowModel,
  getFilteredRowModel,
  getSortedRowModel,
  getPaginationRowModel,
  type ColumnDef as TanColumnDef,
  type PaginationState,
  type FilterFn,
  type Row,
} from '@tanstack/vue-table'
import {
  ChevronLeftIcon,
  ChevronRightIcon,
  ChevronsLeftIcon,
  ChevronsRightIcon,
  ChevronUpIcon,
  ChevronDownIcon,
  ChevronsUpDownIcon,
} from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { AppColumnDef } from '@/lib/appTable'
import type { FilterOption } from '@/lib/dataTableSource'

// TanStack's `useVueTable` is not generic-friendly enough to infer TRow through
// this wrapper, so we bridge to its defaulted row type at this boundary and
// re-assert TRow for consumers via the `#cell` slot — every view gets fully
// typed cell rendering with zero casts on the consumer side.
type AnyRow = Record<string, unknown>

const props = withDefaults(
  defineProps<{
    rows: TRow[]
    columns: AppColumnDef<TRow>[]
    filterOptions?: Record<string, FilterOption[]>
    emptyText: string
    label: string
    loading?: boolean
    searchable?: boolean
    rowKey?: string
    // Identity used to cache/restore this table's view state across navigation.
    // Defaults to route path + label, which is unique per logical table. Pass an
    // explicit key only when one route renders multiple tables with equal labels,
    // or pass an empty string to opt out of persistence.
    stateKey?: string
  }>(),
  { loading: false, searchable: true, rowKey: 'id' },
)

const emit = defineEmits<{ rowClick: [payload: { row: TRow; rowIndex: number }] }>()

defineSlots<{
  cell(props: {
    column: AppColumnDef<TRow>
    row: TRow
    value: unknown
    columnIndex: number
  }): unknown
  /** View-specific controls rendered in the toolbar row, before the search box. */
  toolbar?(props: Record<string, never>): unknown
}>()

// Column defs in their framework-agnostic form (what the template renders
// against) and the mapped TanStack form (what the headless engine consumes).
const cols = computed(() => props.columns as unknown as AppColumnDef<AnyRow>[])
const hasHeaderFilters = computed(() => cols.value.some((c) => c.headerFilter))

// ── Map AppColumnDef → TanStack ColumnDef ────────────────────────────────────
// `accessorKey` reads a top-level property (all current fields are top-level;
// nested object props like `asset` / `current_location` are returned whole and
// rendered from `row` in the slot). Sorting/filtering map 1:1, and a custom
// `comparator` is adapted to TanStack's `sortingFn` signature.
function toTanColumns(columns: AppColumnDef<AnyRow>[]): TanColumnDef<AnyRow>[] {
  return columns.map((c) => {
    const tan: TanColumnDef<AnyRow> = {
      id: c.field,
      accessorKey: c.field,
      header: c.header,
      enableSorting: c.sortable === true,
      enableColumnFilter: c.headerFilter === 'select' || c.headerFilter === 'text',
      // select → exact match; text → case-insensitive substring.
      filterFn: c.headerFilter === 'select' ? 'equals' : 'includesString',
      meta: { minWidth: c.minWidth, type: c.type },
    }
    if (c.comparator) {
      tan.sortingFn = (rowA: Row<AnyRow>, rowB: Row<AnyRow>) =>
        c.comparator!(rowA.getValue(c.field), rowB.getValue(c.field), rowA.original, rowB.original)
    }
    return tan
  })
}

// Columns are stable for a table instance's lifetime (views remount via
// `:key="activeTab"`), so we map once at setup.
const tanColumns = toTanColumns(cols.value)

// ── Global (toolbar) search: substring across every column ───────────────────
// TanStack's global filter is evaluated per (row, column); a row passes if ANY
// column matches. We normalize values to a searchable string so object fields
// (asset.name) and dates are matchable instead of "[object Object]".
function toSearchable(value: unknown): string {
  if (value == null) return ''
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') return String(value)
  if (value instanceof Date) return value.toISOString()
  if (typeof value === 'object') {
    const v = value as Record<string, unknown>
    // Prefer the human identifier when present (asset.name, location.name, …).
    return toSearchable(v.name ?? v.label ?? v.number ?? v.code)
  }
  return String(value)
}

const globalFilterFn: FilterFn<AnyRow> = (row, columnId, filterValue) => {
  const q = String(filterValue ?? '')
    .trim()
    .toLowerCase()
  if (q === '') return true
  return toSearchable(row.getValue(columnId)).toLowerCase().includes(q)
}

// ── Owned state (manual control so we can persist/restore) ───────────────────
const sorting = ref<SortingState>([])
const columnFilters = ref<ColumnFiltersState>([])
const globalFilter = ref('')
const pagination = ref<PaginationState>({ pageIndex: 0, pageSize: 10 })

// Search box value (debounced into `globalFilter` to avoid per-keystroke work).
const search = ref('')
const applySearch = useDebounceFn((v: string) => {
  globalFilter.value = v
}, 200)
watch(search, (v) => applySearch(v))

const table = useVueTable<AnyRow>({
  // `rows` change reactively as data loads.
  get data() {
    return props.rows as unknown as AnyRow[]
  },
  columns: tanColumns,
  state: {
    get sorting() {
      return sorting.value
    },
    get columnFilters() {
      return columnFilters.value
    },
    get globalFilter() {
      return globalFilter.value
    },
    get pagination() {
      return pagination.value
    },
  },
  onSortingChange: (u) => {
    sorting.value = typeof u === 'function' ? u(sorting.value) : u
  },
  onColumnFiltersChange: (u) => {
    columnFilters.value = typeof u === 'function' ? u(columnFilters.value) : u
  },
  onGlobalFilterChange: (u) => {
    globalFilter.value = typeof u === 'function' ? u(globalFilter.value) : u
  },
  onPaginationChange: (u) => {
    pagination.value = typeof u === 'function' ? u(pagination.value) : u
  },
  enableMultiSort: true,
  globalFilterFn,
  getCoreRowModel: getCoreRowModel(),
  getFilteredRowModel: getFilteredRowModel(),
  getSortedRowModel: getSortedRowModel(),
  getPaginationRowModel: getPaginationRowModel(),
})

// ── Derived counts ───────────────────────────────────────────────────────────
// The filtered total is the real source of truth (reacts to search + column
// filters). The "Showing X to Y of Z" line uses it as Z so the count always
// reflects the active view; when filters narrow the set, the original unfiltered
// total is shown in parentheses.
const filteredCount = computed(() => table.getFilteredRowModel().rows.length)
const totalCount = computed(() => props.rows.length)
const pageCount = computed(() => Math.max(table.getPageCount(), 1))
const pageRows = computed(() => table.getRowModel().rows)

const hasActiveFilters = computed(
  () => globalFilter.value.trim() !== '' || columnFilters.value.length > 0,
)

const pageInfo = computed(() => {
  const { pageIndex, pageSize } = pagination.value
  const total = filteredCount.value
  return {
    start: total === 0 ? 0 : pageIndex * pageSize + 1,
    end: Math.min((pageIndex + 1) * pageSize, total),
    total,
    originalTotal: totalCount.value,
  }
})

function columnOf(field: string) {
  return table.getColumn(field)
}
function sortDirOf(field: string): false | 'asc' | 'desc' {
  const s = sorting.value.find((it) => it.id === field)
  return s ? (s.desc ? 'desc' : 'asc') : false
}
function toggleSort(field: string, e: MouseEvent) {
  const handler = columnOf(field)?.getToggleSortingHandler()
  handler?.(e)
}
function getFilterValue(field: string): string {
  return (columnOf(field)?.getFilterValue() as string | undefined) ?? ''
}
function setFilterValue(field: string, v: string | undefined) {
  columnOf(field)?.setFilterValue(v)
}

function rowKeyValue(row: Row<AnyRow>, fallback: number): string | number {
  const k = row.original[props.rowKey]
  return typeof k === 'string' || typeof k === 'number' ? k : fallback
}

function onRowClick(row: Row<AnyRow>, index: number) {
  emit('rowClick', { row: row.original as unknown as TRow, rowIndex: index })
}

// ── Pagination ──
// "All" → a page size larger than any realistic row count.
const ALL_ROWS = 100_000

function firstPage() {
  table.setPageIndex(0)
}
function prevPage() {
  table.previousPage()
}
function nextPage() {
  table.nextPage()
}
function lastPage() {
  table.setPageIndex(table.getPageCount() - 1)
}
function setPageSize(size: number) {
  // Standard UX: changing page size returns to the first page.
  pagination.value = { pageIndex: 0, pageSize: size }
}

// Reset to page 1 whenever the filtered set changes under the user (so they're
// never stranded on a page that no longer exists).
watch(filteredCount, () => {
  if (pagination.value.pageIndex !== 0) pagination.value = { ...pagination.value, pageIndex: 0 }
})

// ── Persist / restore view state across navigation ───────────────────────────
// Capture the cache key once, at setup — NOT reactively. By the time this
// component unmounts (on navigation), the global route has already advanced to
// the destination, so reading `route.path` then would yield the wrong key.
const route = useRoute()
const stateKey = props.stateKey ?? `${route.path}::${props.label}`

function saveSnapshot() {
  if (!stateKey) return
  tableStateCache.set(stateKey, {
    globalFilter: globalFilter.value,
    sorting: sorting.value,
    columnFilters: columnFilters.value,
    pageSize: pagination.value.pageSize,
  })
}

watch([sorting, columnFilters, globalFilter, () => pagination.value.pageSize], saveSnapshot, {
  deep: true,
})

// Restore once row data is present. `pageIndex` is intentionally NOT restored:
// the table resets it to 0 whenever sort/filter changes (standard UX), and
// re-applying it fights that reset across flush cycles. Sort, filters, search,
// and pageSize ARE preserved.
let restored = false
function restore() {
  if (restored || !stateKey) return
  restored = true
  const snap = tableStateCache.get(stateKey)
  if (!snap) return
  sorting.value = snap.sorting
  columnFilters.value = snap.columnFilters
  globalFilter.value = snap.globalFilter
  search.value = snap.globalFilter
  pagination.value = { pageIndex: 0, pageSize: snap.pageSize }
}

watch(
  () => props.rows.length,
  (n) => {
    if (n > 0) restore()
  },
  { immediate: true },
)
</script>

<template>
  <div class="app-data-table">
    <div v-if="searchable || $slots.toolbar" class="data-table-toolbar">
      <!-- View-specific controls (e.g. an external filter select) that belong
           on the same row as the search box instead of a separate bar. -->
      <slot name="toolbar" />
      <Input
        v-if="searchable"
        v-model="search"
        :placeholder="`Search ${label.toLowerCase()}…`"
        class="data-table-search"
      />
    </div>

    <div class="app-data-table-scroll">
      <table class="app-data-table-table">
        <colgroup>
          <col
            v-for="c in cols"
            :key="c.field"
            class="app-data-table-col"
            :style="c.minWidth ? { '--col-min-width': c.minWidth } : undefined"
          />
        </colgroup>

        <thead class="app-data-table-thead">
          <tr class="app-data-table-head-row">
            <th
              v-for="c in cols"
              :key="c.field"
              scope="col"
              class="app-data-table-th"
              :class="{
                'app-data-table-th-sortable': c.sortable,
                'app-data-table-th-center': c.align === 'center',
              }"
              :aria-sort="
                sortDirOf(c.field) === 'asc'
                  ? 'ascending'
                  : sortDirOf(c.field) === 'desc'
                    ? 'descending'
                    : undefined
              "
            >
              <Button
                v-if="c.sortable"
                variant="ghost"
                class="app-data-table-sort-btn"
                @click="(e: MouseEvent) => toggleSort(c.field, e)"
              >
                <span class="app-data-table-th-label">{{ c.header }}</span>
                <ChevronUpIcon
                  v-if="sortDirOf(c.field) === 'asc'"
                  class="app-data-table-sort-icon app-data-table-sort-icon-active"
                />
                <ChevronDownIcon
                  v-else-if="sortDirOf(c.field) === 'desc'"
                  class="app-data-table-sort-icon app-data-table-sort-icon-active"
                />
                <ChevronsUpDownIcon v-else class="app-data-table-sort-icon" />
              </Button>
              <span v-else class="app-data-table-th-label">{{ c.header }}</span>
            </th>
          </tr>

          <tr v-if="hasHeaderFilters" class="app-data-table-filter-row">
            <th v-for="c in cols" :key="c.field" scope="col" class="app-data-table-filter-cell">
              <Select
                v-if="c.headerFilter === 'select' && filterOptions?.[c.field]"
                :model-value="getFilterValue(c.field) || '__all__'"
                @update:model-value="
                  (v) => setFilterValue(c.field, v === '__all__' ? undefined : String(v))
                "
              >
                <SelectTrigger class="table-filter-trigger"
                  ><SelectValue placeholder="All"
                /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="__all__">All</SelectItem>
                  <SelectItem
                    v-for="opt in filterOptions[c.field]"
                    :key="opt.value"
                    :value="opt.value"
                    >{{ opt.label }}</SelectItem
                  >
                </SelectContent>
              </Select>
              <Input
                v-else-if="c.headerFilter === 'text'"
                :model-value="getFilterValue(c.field)"
                placeholder="Filter…"
                class="table-filter-trigger"
                @update:model-value="(v: string | number) => setFilterValue(c.field, String(v))"
              />
            </th>
          </tr>
        </thead>

        <tbody class="app-data-table-tbody">
          <tr v-if="loading && rows.length === 0">
            <td :colspan="cols.length" class="app-data-table-empty">Loading…</td>
          </tr>
          <tr v-else-if="totalCount === 0">
            <td :colspan="cols.length" class="app-data-table-empty">{{ emptyText }}</td>
          </tr>
          <tr v-else-if="filteredCount === 0">
            <td :colspan="cols.length" class="app-data-table-empty">
              No {{ label.toLowerCase() }} match your filters.
            </td>
          </tr>
          <tr
            v-for="(row, i) in pageRows"
            v-else
            :key="rowKeyValue(row, i)"
            class="app-data-table-row"
            tabindex="0"
            @click="onRowClick(row, i)"
            @keydown.enter.prevent="onRowClick(row, i)"
            @keydown.space.prevent="onRowClick(row, i)"
          >
            <td
              v-for="(c, ci) in cols"
              :key="c.field"
              class="app-data-table-cell"
              :class="{ 'app-data-table-cell-center': c.align === 'center' }"
            >
              <slot
                name="cell"
                :column="c as unknown as AppColumnDef<TRow>"
                :row="row.original as unknown as TRow"
                :value="row.getValue(c.field)"
                :column-index="ci"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="data-table-pagination">
      <span class="data-table-pagination-info">
        Showing {{ pageInfo.start }} to {{ pageInfo.end }} of {{ pageInfo.total }}
        <span v-if="hasActiveFilters" class="data-table-pagination-subtle"
          >({{ pageInfo.originalTotal }} total)</span
        >
      </span>
      <div class="data-table-pagination-controls">
        <div class="data-table-pagination-nav">
          <Button
            variant="outline"
            size="icon-sm"
            :disabled="!table.getCanPreviousPage()"
            aria-label="First page"
            @click="firstPage"
            ><ChevronsLeftIcon
          /></Button>
          <Button
            variant="outline"
            size="icon-sm"
            :disabled="!table.getCanPreviousPage()"
            aria-label="Previous page"
            @click="prevPage"
            ><ChevronLeftIcon
          /></Button>
          <span class="data-table-pagination-page"
            >Page {{ pagination.pageIndex + 1 }} of {{ pageCount }}</span
          >
          <Button
            variant="outline"
            size="icon-sm"
            :disabled="!table.getCanNextPage()"
            aria-label="Next page"
            @click="nextPage"
            ><ChevronRightIcon
          /></Button>
          <Button
            variant="outline"
            size="icon-sm"
            :disabled="!table.getCanNextPage()"
            aria-label="Last page"
            @click="lastPage"
            ><ChevronsRightIcon
          /></Button>
        </div>
        <Select
          :model-value="pagination.pageSize >= ALL_ROWS ? 'all' : String(pagination.pageSize)"
          @update:model-value="(v) => setPageSize(v === 'all' ? ALL_ROWS : Number(v))"
        >
          <SelectTrigger class="data-table-pagination-size"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="10">10 / page</SelectItem>
            <SelectItem value="50">50 / page</SelectItem>
            <SelectItem value="100">100 / page</SelectItem>
            <SelectItem value="all">All</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>
  </div>
</template>
