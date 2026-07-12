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
import { useThroughputReport, type ThroughputFilters } from '@/composables/useThroughputReport'
import { fmtDate, fmtKpiHours } from '@/lib/displayHelpers'
import { THROUGHPUT_STATUS_OPTIONS, reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } = useThroughputReport()

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const status = ref<string>(ALL)

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
    { label: 'MR created', value: summary.value.mr_created },
    { label: 'MR converted', value: summary.value.mr_converted },
    { label: 'WO created', value: summary.value.wo_created },
    { label: 'WO completed', value: summary.value.wo_completed },
    { label: 'WO closed', value: summary.value.wo_closed },
    { label: 'Avg MR→WO', value: fmtKpiHours(summary.value.avg_conversion_hours) },
  ]
})

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: ThroughputFilters = { from: fromDate.value, to: toDate.value }
  if (status.value !== ALL) {
    filters.status = status.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  status.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => load({ from: DEFAULT.from, to: DEFAULT.to }))
</script>

<template>
  <ReportPage
    title="MR / WO Throughput"
    subtitle="Daily maintenance-request and work-order flow, plus average conversion time (R-16)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="tp-from">From</Label>
          <DatePicker id="tp-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="tp-to">To</Label>
          <DatePicker id="tp-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="tp-status">Status</Label>
          <Select v-model="status">
            <SelectTrigger id="tp-status"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All statuses</SelectItem>
              <SelectItem v-for="o in THROUGHPUT_STATUS_OPTIONS" :key="o.value" :value="o.value">
                {{ o.label }}
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
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading throughput…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No activity</p>
          <p class="empty-state-description">No MR or WO lifecycle events in the selected window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'day' : 'days' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Date</th>
                  <th scope="col" class="report-table-num">MR created</th>
                  <th scope="col" class="report-table-num">MR converted</th>
                  <th scope="col" class="report-table-num">MR rejected</th>
                  <th scope="col" class="report-table-num">WO created</th>
                  <th scope="col" class="report-table-num">WO completed</th>
                  <th scope="col" class="report-table-num">WO closed</th>
                  <th scope="col" class="report-table-num">WO cancelled</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.date">
                  <td class="report-cell-strong">{{ fmtDate(row.date) }}</td>
                  <td class="report-table-num">{{ row.mr_created }}</td>
                  <td class="report-table-num">{{ row.mr_converted }}</td>
                  <td class="report-table-num">{{ row.mr_rejected }}</td>
                  <td class="report-table-num">{{ row.wo_created }}</td>
                  <td class="report-table-num">{{ row.wo_completed }}</td>
                  <td class="report-table-num">{{ row.wo_closed }}</td>
                  <td class="report-table-num">{{ row.wo_cancelled }}</td>
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
