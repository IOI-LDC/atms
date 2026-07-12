<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
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
import { useMtbfReport, type MtbfFilters } from '@/composables/useMtbfReport'
import { useLocations } from '@/composables/useLocations'
import { useListOptions } from '@/composables/useListOptions'
import { fmtKpiDays } from '@/lib/displayHelpers'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import { DIMENSION_GROUP_BY_OPTIONS, reportDateWindow } from '@/lib/reportOptions'
import type { MtbfGroupBy } from '@/types'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { loading, error, summary, rows, load } = useMtbfReport()
const { activeLocations, loadLocations } = useLocations()
const { faSubclasses, loadFaSubclasses } = useListOptions()

const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const groupBy = ref<MtbfGroupBy>('asset')
const locationId = ref<string>(ALL)
const faSubclassCode = ref<string>(ALL)
const appliedGroupBy = ref<MtbfGroupBy>('asset')

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)
const dimensionLabel = computed(
  () => DIMENSION_GROUP_BY_OPTIONS.find((o) => o.value === appliedGroupBy.value)?.label ?? 'Group',
)

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: MtbfFilters = { group_by: groupBy.value, from: fromDate.value, to: toDate.value }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (faSubclassCode.value !== ALL) {
    filters.fa_subclass_code = faSubclassCode.value
  }
  appliedGroupBy.value = groupBy.value
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  groupBy.value = 'asset'
  locationId.value = ALL
  faSubclassCode.value = ALL
  appliedGroupBy.value = 'asset'
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadLocations()
  loadFaSubclasses()
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="MTBF / Failure Rate"
    subtitle="Mean time between confirmed failures by asset, class, or location (R-3)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="mtbf-from">From</Label>
          <DatePicker id="mtbf-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mtbf-to">To</Label>
          <DatePicker id="mtbf-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mtbf-group">Group by</Label>
          <Select v-model="groupBy">
            <SelectTrigger id="mtbf-group"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="o in DIMENSION_GROUP_BY_OPTIONS" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="mtbf-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="mtbf-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="mtbf-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="mtbf-class"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All classes</SelectItem>
              <SelectItem v-for="o in faSubclassOptions" :key="o.value" :value="o.value">
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
        :stats="[
          { label: 'Overall MTBF', value: fmtKpiDays(summary.mtbf_days) },
          { label: 'Failures', value: summary.failure_count },
          { label: 'Failure rate', value: `${summary.failure_rate_per_day.toFixed(3)} /day` },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading" class="loading-state">Loading MTBF…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No failures</p>
          <p class="empty-state-description">No confirmed failures in the selected window.</p>
        </div>
        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">{{ dimensionLabel }}</th>
                <th scope="col" class="report-table-num">Failures</th>
                <th scope="col" class="report-table-num">MTBF</th>
                <th scope="col" class="report-table-num">Rate / day</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="String(row.group_key)">
                <td class="report-cell-strong">{{ row.group_label ?? '—' }}</td>
                <td class="report-table-num">{{ row.failure_count }}</td>
                <td class="report-table-num">{{ fmtKpiDays(row.mtbf_days) }}</td>
                <td class="report-table-num report-cell-muted">
                  {{ row.failure_rate_per_day.toFixed(3) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
