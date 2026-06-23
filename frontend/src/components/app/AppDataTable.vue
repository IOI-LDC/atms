<script lang="ts">
import type { SortState, FilterState } from '@ioi-dev/vue-table'

interface TableSnapshot {
  globalSearch: string
  sort: SortState[]
  filters: FilterState[]
  pageIndex: number
  pageSize: number
}

const tableStateCache = new Map<string, TableSnapshot>()
</script>

<script setup lang="ts" generic="TRow">
import { ref, computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import { Table } from '@ioi-dev/vue-table'
import type {
  ColumnDef, IoiTableApi, IoiSemanticEvent,
} from '@ioi-dev/vue-table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import type { FilterOption } from '@/lib/dataTableSource'

// ioi-vue-table's <Table> is not a generic SFC, so it can't infer TRow
// per-instance. We bridge that by casting to its defaulted row type at this
// boundary; the `#cell` slot then re-asserts TRow for consumers, giving every
// view fully-typed cell rendering with zero casts on the consumer side.
type AnyRow = Record<string, unknown>

const props = withDefaults(defineProps<{
  rows: TRow[]
  columns: ColumnDef<TRow>[]
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
}>(), { loading: false, searchable: true, rowKey: 'id' })

const emit = defineEmits<{ rowClick: [payload: { row: TRow; rowIndex: number }] }>()

defineSlots<{
  cell(props: { column: ColumnDef<TRow>; row: TRow; value: unknown; columnIndex: number }): unknown
}>()

const tableColumns = props.columns as unknown as ColumnDef<AnyRow>[]
const tableRows = computed(() => props.rows as unknown as AnyRow[])

const tableRef = ref<IoiTableApi | null>(null)
const search = ref('')
const debouncedSearch = useDebounceFn(
  (v: string) => tableRef.value?.setGlobalSearch(v),
  200,
)
watch(search, debouncedSearch)

// Pagination state — owned locally; the table two-way binds these. We render
// a custom #pagination slot so the page-size selector can offer an "All"
// option (the built-in only labels "N / page").
const pageIndex = ref(0)
const pageSize = ref(10)
// "All" → a page size larger than any realistic row count.
const ALL_ROWS = 100_000

function onRowClick(payload: { row: AnyRow; rowIndex: number }) {
  emit('rowClick', { row: payload.row as unknown as TRow, rowIndex: payload.rowIndex })
}

// ── Persist / restore view state across navigation ────────────────────────────
// Capture the cache key once, at setup — NOT reactively. By the time this
// component unmounts (on navigation), the global route has already advanced to
// the destination, so reading `route.path` then would yield the wrong key.
const route = useRoute()
const stateKey = props.stateKey ?? `${route.path}::${props.label}`

// The exposed table API does NOT include a readable `state`, so we can't snapshot
// it on unmount. Instead we mirror sort + filters from the table's `state-change`
// event (its payload carries the new slices), combine with the search / page
// controls we own, and write the snapshot continuously to the session cache.
let liveSort: SortState[] = []
let liveFilters: FilterState[] = []

function saveSnapshot() {
  if (!stateKey) return
  tableStateCache.set(stateKey, {
    globalSearch: search.value,
    sort: liveSort,
    filters: liveFilters,
    pageIndex: pageIndex.value,
    pageSize: pageSize.value,
  })
}

function onStateChange(e: IoiSemanticEvent) {
  const p = e.payload as { sort?: SortState[]; filters?: FilterState[] }
  if (e.type === 'data:sort') liveSort = (p.sort ?? []).map((s) => ({ ...s }))
  else if (e.type === 'data:filter') liveFilters = (p.filters ?? []).map((f) => ({ ...f }))
  saveSnapshot()
}

watch([pageIndex, pageSize, search], saveSnapshot)

// Restore once both the table API and the row data are ready.
let restored = false
function restore() {
  if (restored || !stateKey) return
  const api = tableRef.value
  if (!api) return
  restored = true
  const snap = tableStateCache.get(stateKey)
  if (snap) {
    liveSort = snap.sort
    liveFilters = snap.filters
    search.value = snap.globalSearch
    api.setGlobalSearch(snap.globalSearch)
    api.setSortState(snap.sort)
    snap.filters.forEach((f) => api.setColumnFilter(f.field, f.filter))
    // Note: pageIndex is intentionally NOT restored. The table resets it to 0
    // whenever sort/filter changes (standard UX), and re-applying it fights the
    // table's async page-reset across multiple flush cycles. Sort, filters,
    // search, and pageSize ARE preserved.
    pageSize.value = snap.pageSize
  }
}

watch(
  () => [tableRef.value, tableRows.value.length] as const,
  ([apiRef, rowsLen]) => {
    if (apiRef && rowsLen > 0) restore()
  },
  { immediate: true },
)
</script>

<template>
  <div class="app-data-table">
    <div v-if="searchable" class="data-table-toolbar">
      <Input v-model="search" :placeholder="`Search ${label.toLowerCase()}…`" class="data-table-search" />
    </div>

    <Table
      ref="tableRef"
      :rows="tableRows"
      :columns="tableColumns"
      :row-key="rowKey"
      :filter-debounce-ms="200"
      :height="480"
      v-model:page-index="pageIndex"
      v-model:page-size="pageSize"
      :show-pagination="true"
      :aria-label="label"
      @row-click="onRowClick"
      @state-change="onStateChange"
    >
      <template #cell="slotProps">
        <slot
          name="cell"
          :column="(slotProps.column as unknown as ColumnDef<TRow>)"
          :row="(slotProps.row as unknown as TRow)"
          :value="slotProps.value"
          :column-index="slotProps.columnIndex"
        />
      </template>

      <template #header-filter="{ column, mode, value, setValue, clear }">
        <Select
          v-if="mode === 'select' && filterOptions?.[column.field]"
          :model-value="value || '__all__'"
          @update:model-value="(v) => (v === '__all__' ? clear() : setValue(String(v)))"
        >
          <SelectTrigger class="table-filter-trigger"><SelectValue placeholder="All" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All</SelectItem>
            <SelectItem
              v-for="opt in filterOptions[column.field]"
              :key="opt.value"
              :value="opt.value"
            >{{ opt.label }}</SelectItem>
          </SelectContent>
        </Select>
        <Input
          v-else-if="mode === 'text'"
          :model-value="value"
          placeholder="Filter…"
          class="table-filter-trigger"
          @update:model-value="(v: string | number) => setValue(String(v))"
        />
      </template>

      <template #empty>
        <div class="empty-state">{{ loading ? 'Loading…' : emptyText }}</div>
      </template>

      <template #pagination="{ pageIndex, pageSize, pageCount, rowCount, canPreviousPage, canNextPage, previousPage, nextPage, setPageSize }">
        <div class="data-table-pagination">
          <span class="data-table-pagination-info">
            {{ rowCount }} item{{ rowCount === 1 ? '' : 's' }}
          </span>
          <div class="data-table-pagination-nav">
            <Button variant="outline" size="sm" class="data-table-pagination-btn" :disabled="!canPreviousPage" aria-label="Previous page" @click="previousPage">Prev</Button>
            <span class="data-table-pagination-page">Page {{ pageIndex + 1 }} of {{ Math.max(pageCount, 1) }}</span>
            <Button variant="outline" size="sm" class="data-table-pagination-btn" :disabled="!canNextPage" aria-label="Next page" @click="nextPage">Next</Button>
            <Select
              :model-value="pageSize >= ALL_ROWS ? 'all' : String(pageSize)"
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
      </template>
    </Table>
  </div>
</template>
