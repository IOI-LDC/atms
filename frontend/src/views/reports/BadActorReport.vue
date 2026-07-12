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
import { useBadActorReport, type BadActorFilters } from '@/composables/useBadActorReport'
import { useLocations } from '@/composables/useLocations'
import { useListOptions } from '@/composables/useListOptions'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import {
  DIMENSION_GROUP_BY_OPTIONS,
  BAD_ACTOR_LIMIT_OPTIONS,
  reportDateWindow,
} from '@/lib/reportOptions'
import type { MtbfGroupBy } from '@/types'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { loading, error, summary, rows, load } = useBadActorReport()
const { activeLocations, loadLocations } = useLocations()
const { faSubclasses, loadFaSubclasses } = useListOptions()

const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const groupBy = ref<MtbfGroupBy>('asset')
const locationId = ref<string>(ALL)
const faSubclassCode = ref<string>(ALL)
const limit = ref<string>('25')
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
  const filters: BadActorFilters = {
    group_by: groupBy.value,
    from: fromDate.value,
    to: toDate.value,
    limit: limit.value,
  }
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
  limit.value = '25'
  appliedGroupBy.value = 'asset'
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to, limit: '25' })
}

onMounted(() => {
  loadLocations()
  loadFaSubclasses()
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to, limit: '25' })
})
</script>

<template>
  <ReportPage
    title="Bad-Actor Analysis"
    subtitle="Assets, classes, or locations with the most confirmed failures (R-6)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="ba-from">From</Label>
          <DatePicker id="ba-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="ba-to">To</Label>
          <DatePicker id="ba-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="ba-group">Group by</Label>
          <Select v-model="groupBy">
            <SelectTrigger id="ba-group"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="o in DIMENSION_GROUP_BY_OPTIONS" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="ba-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="ba-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="ba-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="ba-class"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All classes</SelectItem>
              <SelectItem v-for="o in faSubclassOptions" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="ba-limit">Show</Label>
          <Select v-model="limit">
            <SelectTrigger id="ba-limit"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem v-for="o in BAD_ACTOR_LIMIT_OPTIONS" :key="o.value" :value="o.value">
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
        :stats="[{ label: 'Total failures', value: summary.total_failures }]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading" class="loading-state">Loading bad actors…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No failures</p>
          <p class="empty-state-description">No confirmed failures in the selected window.</p>
        </div>
        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">Rank</th>
                <th scope="col">{{ dimensionLabel }}</th>
                <th scope="col" class="report-table-num">Failures</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in rows" :key="String(row.group_key)">
                <td class="report-cell-muted">{{ i + 1 }}</td>
                <td class="report-cell-strong">{{ row.group_label ?? '—' }}</td>
                <td class="report-table-num">{{ row.failure_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
