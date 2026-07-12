<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { DatePicker } from '@/components/ui/date-picker'
import { Progress } from '@/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  usePmComplianceReport,
  type PmComplianceFilters,
  PM_COMPLIANCE_DEFAULT_FROM,
  PM_COMPLIANCE_DEFAULT_TO,
} from '@/composables/usePmComplianceReport'
import { useLocations } from '@/composables/useLocations'
import { usePmRules } from '@/composables/usePmRules'
import { fmtKpiPercent } from '@/lib/displayHelpers'
import { PM_COMPLIANCE_GROUP_BY_OPTIONS } from '@/lib/reportOptions'
import type { PmComplianceGroupBy } from '@/types'

const ALL = '__all__'

const { loading, error, summary, rows, load } = usePmComplianceReport()
const { activeLocations, loadLocations } = useLocations()
const { rules, loadRules } = usePmRules()

const fromDate = ref(PM_COMPLIANCE_DEFAULT_FROM)
const toDate = ref(PM_COMPLIANCE_DEFAULT_TO)
const groupBy = ref<PmComplianceGroupBy>('rule')
const locationId = ref<string>(ALL)
const pmRuleId = ref<string>(ALL)

const appliedGroupBy = ref<PmComplianceGroupBy>('rule')

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

const dimensionLabel = computed(
  () =>
    PM_COMPLIANCE_GROUP_BY_OPTIONS.find((o) => o.value === appliedGroupBy.value)?.label ?? 'Group',
)

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: PmComplianceFilters = { group_by: groupBy.value }
  if (fromDate.value) {
    filters.from = fromDate.value
  }
  if (toDate.value) {
    filters.to = toDate.value
  }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (pmRuleId.value !== ALL) {
    filters.pm_rule_id = pmRuleId.value
  }
  appliedGroupBy.value = groupBy.value
  load(filters)
}

function clearFilters() {
  fromDate.value = PM_COMPLIANCE_DEFAULT_FROM
  toDate.value = PM_COMPLIANCE_DEFAULT_TO
  groupBy.value = 'rule'
  locationId.value = ALL
  pmRuleId.value = ALL
  appliedGroupBy.value = 'rule'
  load({ group_by: 'rule', from: PM_COMPLIANCE_DEFAULT_FROM, to: PM_COMPLIANCE_DEFAULT_TO })
}

onMounted(() => {
  loadLocations()
  loadRules()
  load({ group_by: 'rule', from: PM_COMPLIANCE_DEFAULT_FROM, to: PM_COMPLIANCE_DEFAULT_TO })
})
</script>

<template>
  <ReportPage
    title="PM Compliance"
    subtitle="On-time PM completion by rule, asset, or location (R-7). Reading-triggered PMs are excluded."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="pmc-from">From</Label>
          <DatePicker id="pmc-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>

        <div class="report-filter">
          <Label for="pmc-to">To</Label>
          <DatePicker id="pmc-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>

        <div class="report-filter">
          <Label for="pmc-group">Group by</Label>
          <Select v-model="groupBy">
            <SelectTrigger id="pmc-group"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in PM_COMPLIANCE_GROUP_BY_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="pmc-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="pmc-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="pmc-rule">PM rule</Label>
          <Select v-model="pmRuleId">
            <SelectTrigger id="pmc-rule"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All rules</SelectItem>
              <SelectItem v-for="r in rules" :key="r.id" :value="String(r.id)">
                {{ r.name }}
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
        :stats="[
          { label: 'On-time', value: summary.compliant },
          { label: 'Due in window', value: summary.total },
          { label: 'Compliance', value: fmtKpiPercent(summary.percentage) },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading" class="loading-state">Loading compliance…</div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No PM activity</p>
          <p class="empty-state-description">
            No date-triggered PMs were due in the selected window.
          </p>
        </div>

        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">{{ dimensionLabel }}</th>
                <th scope="col" class="report-table-num">On-time</th>
                <th scope="col" class="report-table-num">Due</th>
                <th scope="col">Compliance</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="String(row.group_key)">
                <td class="report-cell-strong">{{ row.group_label ?? '—' }}</td>
                <td class="report-table-num">{{ row.compliant }}</td>
                <td class="report-table-num">{{ row.total }}</td>
                <td>
                  <div class="report-bar">
                    <Progress :value="row.percentage ?? 0" />
                    <span class="report-bar-value">{{ fmtKpiPercent(row.percentage) }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
