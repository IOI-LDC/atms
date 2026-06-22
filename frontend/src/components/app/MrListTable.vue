<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { Table } from '@ioi-dev/vue-table'
import type { ColumnDef, ServerDataOptions } from '@ioi-dev/vue-table'
import { Button } from '@/components/ui/button'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { mrColumns, mrFilterOptions } from '@/lib/mrColumns'
import type {
  MaintenanceRequest, MaintenanceRequestStatus, MrType, Priority,
} from '@/types'
import {
  mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, mrTypeLabel, fmtDate,
} from '@/lib/displayHelpers'

// `Table` does not infer TRow per-instance in templates (the library's own demos
// cast too), so we cast columns/server-options to the component's defaulted row
// type at the binding site and cast slot data back to our concrete type. Real
// types only — no `any`.
type TableRow = Record<string, unknown>
const columns = mrColumns as unknown as ColumnDef<TableRow>[]

const props = defineProps<{
  source: ServerDataOptions<MaintenanceRequest>
  emptyText: string
  label: string
}>()

const router = useRouter()
// Minimal interface for the methods we call on the <Table> instance via ref.
// (`InstanceType<typeof Table>` is invalid — Table is a generic function
// component, not constructable.) We only ever call methods, never read state.
interface TableInstance {
  refresh: () => void
  fetchMore: () => Promise<void>
}
const tableRef = ref<TableInstance | null>(null)

// Track "has more" locally by observing fetch results. Reading reactive exposed
// state off the <Table> instance via template ref is unreliable, so we wrap the
// source's onFetchSuccess (reliably invoked) and store the result here. Only
// methods (fetchMore/refresh) are called via the table ref.
const hasMore = ref(false)
const wrappedSource: ServerDataOptions<TableRow> = {
  ...(props.source as unknown as ServerDataOptions<TableRow>),
  onFetchSuccess: (result) => {
    hasMore.value = result.hasMore ?? (result.nextCursor != null)
    props.source.onFetchSuccess?.(result as never)
  },
}

function onRowClick(payload: { row: TableRow }) {
  const id = (payload.row as unknown as MaintenanceRequest).id
  router.push(`/maintenance/requests/${id}`)
}

function refresh() {
  tableRef.value?.refresh()
}

defineExpose({ refresh })
</script>

<template>
  <div class="mr-list-table">
    <Table
      ref="tableRef"
      data-mode="server"
      :server-options="wrappedSource"
      :columns="columns"
      row-key="id"
      :filter-debounce-ms="300"
      :height="480"
      :aria-label="label"
      @row-click="onRowClick"
    >
      <template #cell="{ column, row, value }">
        <RouterLink
          v-if="column.field === 'number'"
          :to="`/maintenance/requests/${(row as unknown as MaintenanceRequest).id}`"
          class="table-link"
        >{{ value }}</RouterLink>

        <span v-else-if="column.field === 'asset'" class="table-cell-stack">
          <span class="table-cell-primary">{{ (value as MaintenanceRequest['asset'])?.name }}</span>
          <span class="table-cell-secondary">{{ (value as MaintenanceRequest['asset'])?.erp_asset_code }}</span>
        </span>

        <span v-else-if="column.field === 'priority'" :class="priorityClass(value as Priority)">
          {{ priorityLabel(value as Priority) }}
        </span>

        <span v-else-if="column.field === 'status'" :class="mrStatusClass(value as MaintenanceRequestStatus)">
          {{ mrStatusLabel(value as MaintenanceRequestStatus) }}
        </span>

        <span v-else-if="column.field === 'type'">{{ mrTypeLabel(value as MrType) }}</span>

        <span v-else-if="column.field === 'created_at'">{{ fmtDate(value as string) }}</span>

        <span v-else-if="column.field === 'description'" class="table-cell-truncate">
          {{ value ?? '—' }}
        </span>

        <template v-else>{{ value }}</template>
      </template>

      <template #header-filter="{ column, mode, value, setValue, clear }">
        <Select
          v-if="mode === 'select' && mrFilterOptions[column.field]"
          :model-value="value || '__all__'"
          @update:model-value="(v) => (v === '__all__' ? clear() : setValue(String(v)))"
        >
          <SelectTrigger class="table-filter-trigger"><SelectValue placeholder="All" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All</SelectItem>
            <SelectItem
              v-for="opt in mrFilterOptions[column.field]"
              :key="opt.value"
              :value="opt.value"
            >{{ opt.label }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #empty>
        <div class="empty-state">{{ emptyText }}</div>
      </template>
    </Table>

    <div v-if="hasMore" class="load-more-row">
      <Button variant="outline" size="sm" @click="tableRef?.fetchMore()">Load more</Button>
    </div>
  </div>
</template>
