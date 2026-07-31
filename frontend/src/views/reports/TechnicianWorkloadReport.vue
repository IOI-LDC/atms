<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { DatePicker } from '@/components/ui/date-picker'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useTechnicianWorkloadReport,
  type TechnicianWorkloadFilters,
} from '@/composables/useTechnicianWorkloadReport'
import { useUsers } from '@/composables/useUsers'
import { useAuthStore } from '@/stores/auth.store'
import { fmtKpiHours, fmtKpiDays } from '@/lib/displayHelpers'
import { reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const auth = useAuthStore()
const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  useTechnicianWorkloadReport()
const { users, loadUsers } = useUsers()

const canFilterByTechnician = computed(() => auth.isAdminOrManager)
const technicianOptions = computed(() => users.value.filter((u) => u.role?.code === 'technician'))

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const technicianId = ref<string>(ALL)

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

const summaryStats = computed(() => {
  if (!summary.value) {
    return []
  }
  return [
    { label: 'Work orders', value: summary.value.total_work_orders },
    { label: 'Backlog', value: summary.value.total_backlog },
    { label: 'Completed', value: summary.value.total_completed },
    { label: 'Avg duration', value: fmtKpiHours(summary.value.avg_duration_hours) },
    { label: 'Avg backlog age', value: fmtKpiDays(summary.value.avg_backlog_age_days) },
  ]
})

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: TechnicianWorkloadFilters = { from: fromDate.value, to: toDate.value }
  if (technicianId.value !== ALL) {
    filters.technician_id = technicianId.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  technicianId.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  if (canFilterByTechnician.value) {
    loadUsers()
  }
  load({ from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="Workload by Technician"
    subtitle="Operational work-order load per technician (R-15). No productivity or labor metrics."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="tw-from">From</Label>
          <DatePicker id="tw-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="tw-to">To</Label>
          <DatePicker id="tw-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div v-if="canFilterByTechnician" class="report-filter">
          <Label for="tw-tech">Technician</Label>
          <Select v-model="technicianId">
            <SelectTrigger id="tw-tech"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All technicians</SelectItem>
              <SelectItem v-for="u in technicianOptions" :key="u.id" :value="String(u.id)">
                {{ u.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading || !!dateRangeError" @click="applyFilters">Apply</Button>
        </div>
      </div>
      <p v-if="dateRangeError" class="form-error" role="alert">{{ dateRangeError }}</p>
    </template>

    <template #summary>
      <ReportSummaryStats v-if="summary" :stats="summaryStats" />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading workload…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No work orders</p>
          <p class="empty-state-description">No work orders assigned in the selected window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'technician' : 'technicians' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Technician</th>
                  <th scope="col" class="report-table-num">Total</th>
                  <th scope="col" class="report-table-num">Open</th>
                  <th scope="col" class="report-table-num">In prog.</th>
                  <th scope="col" class="report-table-num">Completed</th>
                  <th scope="col" class="report-table-num">Cancelled</th>
                  <th scope="col" class="report-table-num">Backlog</th>
                  <th scope="col" class="report-table-num">Avg duration</th>
                  <th scope="col" class="report-table-num">Avg backlog age</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.technician_id">
                  <td class="report-cell-strong">{{ row.technician_name }}</td>
                  <td class="report-table-num report-cell-strong">{{ row.total_count }}</td>
                  <td class="report-table-num">{{ row.open_count }}</td>
                  <td class="report-table-num">{{ row.in_progress_count }}</td>
                  <td class="report-table-num">{{ row.completed_count }}</td>
                  <td class="report-table-num">{{ row.cancelled_count }}</td>
                  <td class="report-table-num">{{ row.backlog_count }}</td>
                  <td class="report-table-num">{{ fmtKpiHours(row.avg_duration_hours) }}</td>
                  <td class="report-table-num">{{ fmtKpiDays(row.avg_backlog_age_days) }}</td>
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
