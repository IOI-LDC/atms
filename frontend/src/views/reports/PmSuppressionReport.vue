<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import AssetCombobox from '@/components/app/AssetCombobox.vue'
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
  usePmSuppressionReport,
  type PmSuppressionFilters,
} from '@/composables/usePmSuppressionReport'
import { usePmRules } from '@/composables/usePmRules'
import { fmtDate, fmtDateTime, pmDecisionTypeLabel, pmDecisionTypeClass } from '@/lib/displayHelpers'
import { PM_DECISION_TYPE_OPTIONS, reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  usePmSuppressionReport()
const { rules, loadRules } = usePmRules()

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const pmRuleId = ref<string>(ALL)
const selectedAsset = ref<{ id: number; label: string } | null>(null)
const decisionType = ref<string>(ALL)

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

function untilLabel(row: { suppressed_until_date: string | null; suppressed_until_reading: number | null }): string {
  if (row.suppressed_until_date) {
    return fmtDate(row.suppressed_until_date)
  }
  if (row.suppressed_until_reading != null) {
    return String(row.suppressed_until_reading)
  }
  return '—'
}

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: PmSuppressionFilters = { from: fromDate.value, to: toDate.value }
  if (pmRuleId.value !== ALL) {
    filters.pm_rule_id = pmRuleId.value
  }
  if (selectedAsset.value) {
    filters.asset_id = selectedAsset.value.id
  }
  if (decisionType.value !== ALL) {
    filters.decision_type = decisionType.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  pmRuleId.value = ALL
  selectedAsset.value = null
  decisionType.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadRules()
  load({ from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="PM Suppression Register"
    subtitle="Suppressed or overridden PM occurrences — who, when, and why (R-21)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="ps-from">From</Label>
          <DatePicker id="ps-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="ps-to">To</Label>
          <DatePicker id="ps-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="ps-rule">PM rule</Label>
          <Select v-model="pmRuleId">
            <SelectTrigger id="ps-rule"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All rules</SelectItem>
              <SelectItem v-for="r in rules" :key="r.id" :value="String(r.id)">{{ r.name }}</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="ps-asset">Asset</Label>
          <AssetCombobox v-model="selectedAsset" input-id="ps-asset" />
        </div>
        <div class="report-filter">
          <Label for="ps-decision">Decision</Label>
          <Select v-model="decisionType">
            <SelectTrigger id="ps-decision"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All decisions</SelectItem>
              <SelectItem v-for="o in PM_DECISION_TYPE_OPTIONS" :key="o.value" :value="o.value">
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
      <ReportSummaryStats
        v-if="summary"
        :stats="[{ label: 'Suppressions', value: summary.total_suppressions }]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">
          Loading suppressions…
        </div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No suppressions</p>
          <p class="empty-state-description">No PM occurrences were suppressed in the window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'record' : 'records' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Decided at</th>
                  <th scope="col">PM rule</th>
                  <th scope="col">Asset</th>
                  <th scope="col">Request</th>
                  <th scope="col">Decision</th>
                  <th scope="col">Until</th>
                  <th scope="col">Decided by</th>
                  <th scope="col">Reason</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td class="report-cell-muted">{{ fmtDateTime(row.decided_at) }}</td>
                  <td>{{ row.pm_rule.name ?? '—' }}</td>
                  <td>
                    <span class="report-cell-strong">{{ row.asset.name }}</span>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td>
                    <RouterLink
                      v-if="row.maintenance_request.id"
                      :to="`/maintenance/requests/${row.maintenance_request.id}`"
                      class="report-link"
                    >
                      {{ row.maintenance_request.number }}
                    </RouterLink>
                    <span v-else class="report-cell-muted">—</span>
                  </td>
                  <td>
                    <span :class="pmDecisionTypeClass(row.decision_type)">
                      {{ pmDecisionTypeLabel(row.decision_type) }}
                    </span>
                  </td>
                  <td class="report-cell-muted">{{ untilLabel(row) }}</td>
                  <td class="report-cell-muted">{{ row.decided_by.name ?? '—' }}</td>
                  <td class="report-cell-muted">{{ row.reason ?? '—' }}</td>
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
