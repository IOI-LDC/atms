<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import AssetIdentity from '@/components/app/AssetIdentity.vue'
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
import { useAssetUsageReport, type AssetUsageFilters } from '@/composables/useAssetUsageReport'
import { useLocations } from '@/composables/useLocations'
import { useListOptions } from '@/composables/useListOptions'
import { fmtDateTime } from '@/lib/displayHelpers'
import { toMaintenanceCategoryIdFilterOptions } from '@/lib/assetColumns'
import { ASSET_USAGE_GROUP_BY_OPTIONS, reportDateWindow } from '@/lib/reportOptions'
import type { AssetUsageGroupBy } from '@/types'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { loading, error, summary, rows, readingType, unit, groupHeader, isPerAsset, load } =
  useAssetUsageReport()
const { activeLocations, loadLocations } = useLocations()
const { readingTypes, loadReadingTypes, maintenanceCategories, loadMaintenanceCategories } =
  useListOptions()

const categoryOptions = computed(() =>
  toMaintenanceCategoryIdFilterOptions(maintenanceCategories.value),
)

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const readingTypeId = ref<string>(ALL)
const groupBy = ref<AssetUsageGroupBy>('asset')
const locationId = ref<string>(ALL)
const categoryId = ref<string>(ALL)

const dateRangeError = computed(() =>
  fromDate.value && toDate.value && toDate.value < fromDate.value
    ? 'The "To" date cannot be earlier than the "From" date.'
    : '',
)

/** Usage column header carries the unit so a bare number is never ambiguous. */
const usageHeader = computed(() => (unit.value ? `Usage (${unit.value})` : 'Usage'))

function currentFilters(): AssetUsageFilters {
  const filters: AssetUsageFilters = {
    group_by: groupBy.value,
    from: fromDate.value,
    to: toDate.value,
  }
  if (readingTypeId.value !== ALL) {
    filters.usage_reading_type_id = readingTypeId.value
  }
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (categoryId.value !== ALL) {
    filters.maintenance_category_id = categoryId.value
  }
  return filters
}

function applyFilters() {
  if (dateRangeError.value) {
    return
  }
  load(currentFilters())
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  readingTypeId.value = ALL
  groupBy.value = 'asset'
  locationId.value = ALL
  categoryId.value = ALL
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadReadingTypes()
  loadLocations()
  loadMaintenanceCategories()
  load({ group_by: 'asset', from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="Most-Used Assets"
    subtitle="Which assets did the most work in the period, by operating hours, distance, or depth (R-22)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="usage-type">Usage measure</Label>
          <Select v-model="readingTypeId">
            <SelectTrigger id="usage-type"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">Default measure</SelectItem>
              <SelectItem v-for="t in readingTypes" :key="t.id" :value="String(t.id)">
                {{ t.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="usage-group-by">Group by</Label>
          <Select v-model="groupBy">
            <SelectTrigger id="usage-group-by"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="opt in ASSET_USAGE_GROUP_BY_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="usage-from">From</Label>
          <DatePicker id="usage-from" v-model="fromDate" />
        </div>

        <div class="report-filter">
          <Label for="usage-to">To</Label>
          <DatePicker id="usage-to" v-model="toDate" />
        </div>

        <div class="report-filter">
          <Label for="usage-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="usage-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="usage-category">Maintenance category</Label>
          <Select v-model="categoryId">
            <SelectTrigger id="usage-category"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All categories</SelectItem>
              <SelectItem v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading || !!dateRangeError" @click="applyFilters">Apply</Button>
        </div>

        <p v-if="dateRangeError" class="report-filter-note" role="alert">{{ dateRangeError }}</p>
      </div>
    </template>

    <template #summary>
      <ReportSummaryStats
        v-if="summary"
        :stats="[
          { label: `Total ${readingType?.name ?? 'usage'}`, value: summary.total_usage },
          { label: 'Assets with usage', value: summary.assets_with_usage },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <p class="report-filter-note">
          Meters are cumulative, so usage is the difference between where the meter stood entering
          the period and where it stood at the end — not a sum of readings. Only
          <strong>confirmed</strong> readings count.
        </p>

        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading" class="loading-state">Loading usage…</div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No usage recorded</p>
          <p class="empty-state-description">
            No confirmed readings for this measure in the selected period.
          </p>
        </div>

        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">{{ groupHeader }}</th>
                <th scope="col" class="report-table-num">{{ usageHeader }}</th>
                <th v-if="!isPerAsset" scope="col" class="report-table-num">Assets</th>
                <th v-if="isPerAsset" scope="col" class="report-table-num">Latest reading</th>
                <th v-if="isPerAsset" scope="col">Last read</th>
                <th scope="col" class="report-table-num">Readings</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.group_key ?? 'unassigned'">
                <td :class="row.is_unassigned ? 'report-cell-muted' : 'report-cell-strong'">
                  <AssetIdentity v-if="row.asset" :asset="row.asset" stacked />
                  <template v-else>{{ row.group_label }}</template>
                </td>
                <td class="report-table-num report-cell-strong">{{ row.usage }}</td>
                <td v-if="!isPerAsset" class="report-table-num">{{ row.asset_count }}</td>
                <td v-if="isPerAsset" class="report-table-num">{{ row.latest_reading }}</td>
                <td v-if="isPerAsset" class="report-cell-muted">
                  {{ row.last_reading_at ? fmtDateTime(row.last_reading_at) : '—' }}
                </td>
                <td class="report-table-num report-cell-muted">{{ row.reading_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
