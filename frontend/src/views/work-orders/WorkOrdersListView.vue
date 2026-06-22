<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import { useAuthStore } from '@/stores/auth.store'
import { useWorkOrders } from '@/composables/useWorkOrders'
import { woColumns, woFilterOptions } from '@/lib/woColumns'
import type { WorkOrder } from '@/types'
import { woStatusClass, woStatusLabel, priorityClass, priorityLabel, fmtDate } from '@/lib/displayHelpers'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const { myWorkOrders, all, open, inProgress, completed, closed } = useWorkOrders()

// ── Tabs (role-based) ─────────────────────────────────────────────────────────

const tabDefs = computed(() => {
  const tabs: { key: string; label: string }[] = []
  if (auth.isTechnician)      tabs.push({ key: 'my-work-orders', label: 'My Work Orders' })
  if (auth.isAdminOrManager)  tabs.push({ key: 'all', label: 'All' })
  tabs.push({ key: 'open', label: 'Open' })
  tabs.push({ key: 'in-progress', label: 'In Progress' })
  tabs.push({ key: 'completed', label: 'Completed' })
  tabs.push({ key: 'closed', label: 'Closed' })
  return tabs
})

const activeTab = computed(() => {
  const t = route.query.tab as string | undefined
  return tabDefs.value.some(d => d.key === t) ? t! : (tabDefs.value[0]?.key ?? 'open')
})

const activeSlice = computed(() => {
  switch (activeTab.value) {
    case 'my-work-orders': return { rows: myWorkOrders.rows.value, loading: myWorkOrders.loading.value, load: myWorkOrders.load, emptyText: 'No work orders assigned to you.', label: 'My work orders' }
    case 'all':            return { rows: all.rows.value, loading: all.loading.value, load: all.load, emptyText: 'No work orders found.', label: 'All work orders' }
    case 'open':           return { rows: open.rows.value, loading: open.loading.value, load: open.load, emptyText: 'No open work orders.', label: 'Open work orders' }
    case 'in-progress':    return { rows: inProgress.rows.value, loading: inProgress.loading.value, load: inProgress.load, emptyText: 'No work orders in progress.', label: 'Work orders in progress' }
    case 'completed':      return { rows: completed.rows.value, loading: completed.loading.value, load: completed.load, emptyText: 'No work orders awaiting closure.', label: 'Completed work orders' }
    case 'closed':         return { rows: closed.rows.value, loading: closed.loading.value, load: closed.load, emptyText: 'No closed work orders yet.', label: 'Closed work orders' }
    default:               return { rows: open.rows.value, loading: open.loading.value, load: open.load, emptyText: 'No open work orders.', label: 'Open work orders' }
  }
})

watch(activeTab, (tab) => {
  if (tab && route.query.tab !== tab) router.replace({ path: route.path, query: { tab } })
})
watch(activeTab, () => { activeSlice.value.load() }, { immediate: true })

function goDetail(payload: { row: WorkOrder }) {
  router.push(`/work-orders/${payload.row.id}`)
}
</script>

<template>
  <AppLayout>
    <div class="page-section">

      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Work Orders</h1>
          <p class="page-subtitle">Track and manage maintenance work orders</p>
        </div>
      </div>

      <div class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ path: '/work-orders', query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >
          {{ tab.label }}
        </RouterLink>
      </div>

      <AppDataTable
        :key="activeTab"
        :rows="activeSlice.rows"
        :columns="woColumns"
        :filter-options="woFilterOptions"
        :empty-text="activeSlice.emptyText"
        :loading="activeSlice.loading"
        :label="activeSlice.label"
        @row-click="goDetail"
      >
        <template #cell="{ column, row, value }">
          <RouterLink
            v-if="column.field === 'number'"
            :to="`/work-orders/${row.id}`"
            class="table-link"
          >{{ row.number }}</RouterLink>

          <span v-else-if="column.field === 'asset'" class="table-cell-stack">
            <span class="table-cell-primary">{{ row.asset?.name }}</span>
            <span class="table-cell-secondary">{{ row.asset?.erp_asset_code }}</span>
          </span>

          <span v-else-if="column.field === 'priority'" :class="priorityClass(row.priority)">
            {{ priorityLabel(row.priority) }}
          </span>

          <span v-else-if="column.field === 'status'" :class="woStatusClass(row.status)">
            {{ woStatusLabel(row.status) }}
          </span>

          <span v-else-if="column.field === 'assigned_to'">
            {{ row.assigned_to?.name ?? '—' }}
          </span>

          <span v-else-if="column.field === 'started_at'">{{ fmtDate(row.started_at) }}</span>

          <span v-else-if="column.field === 'created_at'">{{ fmtDate(row.created_at) }}</span>

          <template v-else>{{ value }}</template>
        </template>
      </AppDataTable>

    </div>
  </AppLayout>
</template>
