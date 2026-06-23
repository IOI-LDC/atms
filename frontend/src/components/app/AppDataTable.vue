<script setup lang="ts" generic="TRow">
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useDebounceFn } from '@vueuse/core'
import { Table } from '@ioi-dev/vue-table'
import type { ColumnDef, IoiTableApi, SortState, FilterState } from '@ioi-dev/vue-table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import type { FilterOption } from '@/lib/dataTableSource'

// ── Session-scoped state persistence ──────────────────────────────────────────
// Search / filters / sort / page survive in-app navigation (e.g. open a row →
// Back) so the user returns to exactly the view they left. State is held in a
// module-level cache for the SPA session — it is intentionally NOT persisted to
// the URL or storage, so a hard refresh starts clean. Keyed per logical table
// (see `stateKey` below) so every table that uses this wrapper gets it for free.
interface TableSnapshot {
  globalSearch: string
  sort: SortState[]
  filters: FilterState[]
  pageIndex: number
  pageSize: number
  scrollTop: number
}
const tableStateCache = new Map<string, TableSnapshot>()

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
const route = useRoute()
const stateKey = computed(() =>
  props.stateKey ?? `${route.path}::${props.label}`,
)

// Save on unmount (navigating away from the list always unmounts it in the SPA).
onBeforeUnmount(() => {
  if (!stateKey.value) return
  const api = tableRef.value
  const st = api?.state
  if (!st) return
  tableStateCache.set(stateKey.value, {
    globalSearch: st.globalSearch,
    sort: st.sort.map((s) => ({ ...s })),
    filters: st.filters.map((f) => ({ ...f })),
    pageIndex: pageIndex.value,
    pageSize: pageSize.value,
    scrollTop: st.viewport?.scrollTop ?? 0,
  })
})

// Restore once both the table API and the row data are ready. Search / filters /
// sort / page-size don't depend on row count; page-index and scroll are applied
// on the next ticks so they settle against the rendered, paginated content.
let restored = false
function restore() {
  if (restored || !stateKey.value) return
  const api = tableRef.value
  const snap = tableStateCache.get(stateKey.value)
  if (!api || !snap) return
  restored = true

  search.value = snap.globalSearch
  api.setGlobalSearch(snap.globalSearch)
  api.setSortState(snap.sort)
  snap.filters.forEach((f) => api.setColumnFilter(f.field, f.filter))
  pageSize.value = snap.pageSize

  nextTick(() => {
    pageIndex.value = snap.pageIndex
    nextTick(() => api.setViewport?.(snap.scrollTop))
  })
}

watch(
  () => [tableRef.value, tableRows.value.length] as const,
  () => { if (tableRef.value && tableRows.value.length > 0) restore() },
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
