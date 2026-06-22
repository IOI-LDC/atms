<script setup lang="ts" generic="TRow">
import { ref, computed, watch } from 'vue'
import { Table } from '@ioi-dev/vue-table'
import type { ColumnDef } from '@ioi-dev/vue-table'
import { Input } from '@/components/ui/input'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import type { FilterOption } from '@/lib/dataTableSource'

// ioi-vue-table's <Table> doesn't infer TRow per-instance, so we cast to its
// defaulted row type at this boundary. The rows really are TRow (the source
// returns them), so the `#cell` slot below re-asserts TRow for consumers —
// giving every view fully-typed cell rendering with zero casts.
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
}>(), { loading: false, searchable: true, rowKey: 'id' })

const emit = defineEmits<{ rowClick: [payload: { row: TRow; rowIndex: number }] }>()

defineSlots<{
  cell(props: { column: ColumnDef<TRow>; row: TRow; value: unknown; columnIndex: number }): unknown
}>()

const tableColumns = props.columns as unknown as ColumnDef<AnyRow>[]
const tableRows = computed(() => props.rows as unknown as AnyRow[])

const tableRef = ref<{ setGlobalSearch: (text: string) => void } | null>(null)
const search = ref('')
let debounce: ReturnType<typeof setTimeout> | null = null
watch(search, (v) => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(() => tableRef.value?.setGlobalSearch(v), 200)
})

function onRowClick(payload: { row: AnyRow; rowIndex: number }) {
  emit('rowClick', { row: payload.row as unknown as TRow, rowIndex: payload.rowIndex })
}
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
      </template>

      <template #empty>
        <div class="empty-state">{{ loading ? 'Loading…' : emptyText }}</div>
      </template>
    </Table>
  </div>
</template>
