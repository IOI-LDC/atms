<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useWorkOrderBacklogReport,
  type WoBacklogFilters,
} from '@/composables/useWorkOrderBacklogReport'
import { useLocations } from '@/composables/useLocations'
import { useUsers } from '@/composables/useUsers'
import { useListOptions } from '@/composables/useListOptions'
import { useAuthStore } from '@/stores/auth.store'
import {
  fmtDate,
  priorityClass,
  priorityLabel,
  agingBucketClass,
  agingBucketLabel,
} from '@/lib/displayHelpers'
import { WO_BACKLOG_STATUS_OPTIONS } from '@/lib/reportOptions'

const ALL = '__all__'

const auth = useAuthStore()
const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  useWorkOrderBacklogReport()
const { activeLocations, loadLocations } = useLocations()
const { users, loadUsers } = useUsers()
const { priorities, loadPriorities } = useListOptions()

const canFilterByAssignee = computed(() => auth.isAdminOrManager)

const locationId = ref<string>(ALL)
const assignedTo = ref<string>(ALL)
const priority = ref<string>(ALL)
const status = ref<'open' | 'in_progress' | 'both'>('both')

function applyFilters() {
  const filters: WoBacklogFilters = { status: status.value }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (assignedTo.value !== ALL) {
    filters.assigned_to = assignedTo.value
  }
  if (priority.value !== ALL) {
    filters.priority = priority.value
  }
  load(filters)
}

function clearFilters() {
  locationId.value = ALL
  assignedTo.value = ALL
  priority.value = ALL
  status.value = 'both'
  load({ status: 'both' })
}

const summaryStats = computed(() => {
  if (!summary.value) {
    return []
  }
  return [
    { label: 'Total backlog', value: summary.value.total },
    { label: '0–7 days', value: summary.value.by_bucket['0-7'] },
    { label: '8–30 days', value: summary.value.by_bucket['8-30'] },
    { label: '31–90 days', value: summary.value.by_bucket['31-90'] },
    { label: '91+ days', value: summary.value.by_bucket['91+'] },
  ]
})

const priorityBreakdown = computed(() => {
  if (!summary.value) {
    return []
  }
  return Object.entries(summary.value.by_priority).map(([key, count]) => ({
    key,
    label: priorityLabel(key),
    count,
  }))
})

onMounted(() => {
  loadLocations()
  if (canFilterByAssignee.value) {
    loadUsers()
  }
  loadPriorities()
  load({ status: 'both' })
})
</script>

<template>
  <ReportPage
    title="WO Backlog / Aging"
    subtitle="Open and in-progress work orders by age bucket and priority (R-14)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="wob-status">Status</Label>
          <Select v-model="status">
            <SelectTrigger id="wob-status"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in WO_BACKLOG_STATUS_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="wob-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="wob-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div v-if="canFilterByAssignee" class="report-filter">
          <Label for="wob-assignee">Assigned to</Label>
          <Select v-model="assignedTo">
            <SelectTrigger id="wob-assignee"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">Anyone</SelectItem>
              <SelectItem v-for="u in users" :key="u.id" :value="String(u.id)">
                {{ u.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="wob-priority">Priority</Label>
          <Select v-model="priority">
            <SelectTrigger id="wob-priority"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All priorities</SelectItem>
              <SelectItem v-for="opt in priorities" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading" @click="applyFilters">Apply</Button>
        </div>
      </div>
    </template>

    <template #summary>
      <ReportSummaryStats v-if="summary" :stats="summaryStats" />
      <div v-if="priorityBreakdown.length" class="report-chips report-subsummary">
        <span v-for="p in priorityBreakdown" :key="p.key" class="report-chip">
          {{ p.label }} <b>{{ p.count }}</b>
        </span>
      </div>
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading && rows.length === 0" class="loading-state">
          Loading work-order backlog…
        </div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No backlog</p>
          <p class="empty-state-description">
            No open or in-progress work orders match these filters.
          </p>
        </div>

        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'result' : 'results' }}
            <span v-if="hasMore">· more available</span>
          </p>

          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Work order</th>
                  <th scope="col">Asset</th>
                  <th scope="col">Assigned to</th>
                  <th scope="col">Priority</th>
                  <th scope="col">Created</th>
                  <th scope="col" class="report-table-num">Age (days)</th>
                  <th scope="col">Bucket</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td>
                    <RouterLink :to="`/work-orders/${row.id}`" class="report-link">
                      {{ row.number }}
                    </RouterLink>
                  </td>
                  <td>
                    <span class="report-cell-strong">{{ row.asset.name }}</span>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td :class="row.assigned_to ? '' : 'report-cell-muted'">
                    {{ row.assigned_to?.name ?? '—' }}
                  </td>
                  <td><span :class="priorityClass(row.priority)">{{ priorityLabel(row.priority) }}</span></td>
                  <td class="report-cell-muted">{{ fmtDate(row.created_at) }}</td>
                  <td class="report-table-num report-cell-strong">{{ row.age_days }}</td>
                  <td><span :class="agingBucketClass(row.bucket)">{{ agingBucketLabel(row.bucket) }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>

          <ReportLoadMore :has-more="hasMore" :loading="loadingMore" @more="loadMore" />
        </template>
      </div>
    </div>
  </ReportPage>
</template>
