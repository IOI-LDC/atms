<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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
  useMeterProgressionReport,
  type MeterProgressionFilters,
} from '@/composables/useMeterProgressionReport'
import { useListOptions } from '@/composables/useListOptions'
import { fmtDateTime } from '@/lib/displayHelpers'
import { reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  useMeterProgressionReport()
const { readingTypes, loadReadingTypes } = useListOptions()

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const selectedAsset = ref<{ id: number; label: string } | null>(null)
const readingTypeId = ref<string>(ALL)

const todayStr = new Date().toLocaleDateString('en-CA')
const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  const filters: MeterProgressionFilters = { from: fromDate.value, to: toDate.value }
  if (selectedAsset.value) {
    filters.asset_id = selectedAsset.value.id
  }
  if (readingTypeId.value !== ALL) {
    filters.usage_reading_type_id = readingTypeId.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  selectedAsset.value = null
  readingTypeId.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadReadingTypes()
  load({ from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="Meter Reading Progression"
    subtitle="Confirmed readings over time with per-reading change (R-20)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="mp-from">From</Label>
          <DatePicker id="mp-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mp-to">To</Label>
          <DatePicker id="mp-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mp-asset">Asset</Label>
          <AssetCombobox v-model="selectedAsset" input-id="mp-asset" />
        </div>
        <div class="report-filter">
          <Label for="mp-type">Reading type</Label>
          <Select v-model="readingTypeId">
            <SelectTrigger id="mp-type"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All types</SelectItem>
              <SelectItem v-for="t in readingTypes" :key="t.id" :value="String(t.id)">
                {{ t.name }}
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
          { label: 'Readings', value: summary.total_readings },
          { label: 'Confirmed', value: summary.confirmed_readings },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading readings…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No readings</p>
          <p class="empty-state-description">No confirmed readings in the selected window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'reading' : 'readings' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Read at</th>
                  <th scope="col">Asset</th>
                  <th scope="col">Reading type</th>
                  <th scope="col" class="report-table-num">Value</th>
                  <th scope="col" class="report-table-num">Change</th>
                  <th scope="col">Source</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td class="report-cell-muted">{{ fmtDateTime(row.reading_at) }}</td>
                  <td>
                    <span class="report-cell-strong">{{ row.asset.name }}</span>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td>{{ row.reading_type.name }}</td>
                  <td class="report-table-num report-cell-strong">
                    {{ row.reading_value }}<span class="report-cell-muted"> {{ row.reading_type.unit }}</span>
                  </td>
                  <td class="report-table-num">
                    {{ row.delta != null ? row.delta : '—' }}
                  </td>
                  <td class="report-cell-muted">{{ row.source ?? '—' }}</td>
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
