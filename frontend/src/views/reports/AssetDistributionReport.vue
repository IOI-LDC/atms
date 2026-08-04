<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Download } from '@lucide/vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useAssetDistributionReport,
  type AssetDistributionFilters,
} from '@/composables/useAssetDistributionReport'
import { useListOptions } from '@/composables/useListOptions'
import { useReportCsvExport } from '@/composables/useReportCsvExport'
import { toMaintenanceCategoryIdFilterOptions } from '@/lib/assetColumns'
import {
  ASSET_DISTRIBUTION_GROUP_BY_OPTIONS,
  ASSET_KIND_OPTIONS,
  OPERATIONAL_STATUS_OPTIONS,
} from '@/lib/reportOptions'
import type { AssetDistributionGroupBy, AssetKind } from '@/types'

const ALL = '__all__'

const { loading, error, summary, rows, groupHeaders, groupsLabel, load } =
  useAssetDistributionReport()
const { maintenanceCategories, loadMaintenanceCategories } = useListOptions()
const { exporting, exportError, exportCsv } = useReportCsvExport()

const categoryOptions = computed(() =>
  toMaintenanceCategoryIdFilterOptions(maintenanceCategories.value),
)

const groupBy = ref<AssetDistributionGroupBy[]>(['location'])

/**
 * Selection order is the column order, so the incoming array is reconciled
 * rather than taken as-is: already-selected dimensions keep their position and
 * newly-selected ones append. ToggleGroup reports its value as a plain set, so
 * without this the order would follow the DOM instead of the user's intent.
 */
function onDimensionsChange(next: unknown) {
  const selected = (Array.isArray(next) ? next : []) as AssetDistributionGroupBy[]
  const kept = groupBy.value.filter((d) => selected.includes(d))
  const added = selected.filter((d) => !groupBy.value.includes(d))
  const reordered = [...kept, ...added]

  // Silently refuse to drop the last dimension — there is nothing to group by
  // without one. Ignoring the change beats disabling the chip or explaining a
  // rule the user can simply never hit.
  if (reordered.length > 0) {
    groupBy.value = reordered
  }
}

/** 1-based column position, or 0 when the dimension is not selected. */
function dimensionPosition(dimension: AssetDistributionGroupBy): number {
  return groupBy.value.indexOf(dimension) + 1
}
const categoryId = ref<string>(ALL)
const assetKind = ref<string>(ALL)
const operationalStatus = ref<string>(ALL)

function currentFilters(): AssetDistributionFilters {
  const filters: AssetDistributionFilters = { group_by: groupBy.value }
  if (categoryId.value !== ALL) {
    filters.maintenance_category_id = categoryId.value
  }
  if (assetKind.value !== ALL) {
    filters.asset_kind = assetKind.value as AssetKind
  }
  if (operationalStatus.value !== ALL) {
    filters.operational_status = operationalStatus.value
  }
  return filters
}

// Export mirrors what is on screen, so it tracks the filters actually loaded
// rather than whatever the bar currently reads.
const appliedFilters = ref<AssetDistributionFilters>({ group_by: ['location'] })

function runLoad(filters: AssetDistributionFilters) {
  appliedFilters.value = filters
  load(filters)
}

function applyFilters() {
  runLoad(currentFilters())
}

function exportReport() {
  exportCsv('/reports/asset-distribution', appliedFilters.value)
}

function clearFilters() {
  groupBy.value = ['location']
  categoryId.value = ALL
  assetKind.value = ALL
  operationalStatus.value = ALL
  runLoad({ group_by: ['location'] })
}

onMounted(() => {
  loadMaintenanceCategories()
  runLoad({ group_by: ['location'] })
})
</script>

<template>
  <ReportPage
    title="Asset Distribution"
    subtitle="How assets are spread across location, maintenance category and size — tick any combination (R-2)."
  >
    <template #actions>
      <Button variant="outline" :disabled="loading || exporting" @click="exportReport">
        <Download />
        {{ exporting ? 'Exporting…' : 'Export CSV' }}
      </Button>
    </template>

    <template #filters>
      <div class="report-filters">
        <div class="report-filter report-filter-wide">
          <Label>Group by</Label>
          <ToggleGroup
            type="multiple"
            variant="outline"
            :spacing="1"
            :model-value="groupBy"
            aria-label="Dimensions to group by"
            @update:model-value="onDimensionsChange"
          >
            <ToggleGroupItem
              v-for="opt in ASSET_DISTRIBUTION_GROUP_BY_OPTIONS"
              :key="opt.value"
              :value="opt.value"
              class="report-dimension-chip"
            >
              {{ opt.label }}
              <span
                v-if="dimensionPosition(opt.value) > 0"
                class="report-dimension-order"
                :aria-label="`column ${dimensionPosition(opt.value)}`"
                >{{ dimensionPosition(opt.value) }}</span
              >
            </ToggleGroupItem>
          </ToggleGroup>
        </div>

        <div class="report-filter">
          <Label for="abl-category">Maintenance category</Label>
          <Select v-model="categoryId">
            <SelectTrigger id="abl-category"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All categories</SelectItem>
              <SelectItem v-for="opt in categoryOptions" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="abl-kind">Asset kind</Label>
          <Select v-model="assetKind">
            <SelectTrigger id="abl-kind"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All kinds</SelectItem>
              <SelectItem v-for="opt in ASSET_KIND_OPTIONS" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter">
          <Label for="abl-status">Operational status</Label>
          <Select v-model="operationalStatus">
            <SelectTrigger id="abl-status"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All statuses</SelectItem>
              <SelectItem
                v-for="opt in OPERATIONAL_STATUS_OPTIONS"
                :key="opt.value"
                :value="opt.value"
              >
                {{ opt.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="report-filter-tail">
          <p v-if="groupBy.length > 1" class="report-filter-note">
            Columns follow the order selected.
          </p>
          <div class="report-filter-actions">
            <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
            <Button :disabled="loading" @click="applyFilters">Apply</Button>
          </div>
        </div>
      </div>
      <p v-if="exportError" class="report-filter-note" role="alert">{{ exportError }}</p>
    </template>

    <template #summary>
      <ReportSummaryStats
        v-if="summary"
        :stats="[
          { label: 'Total assets', value: summary.total_assets },
          { label: groupsLabel, value: summary.total_groups },
          { label: 'Booked', value: summary.total_booked },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>

        <div v-else-if="loading" class="loading-state">Loading distribution…</div>

        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No assets</p>
          <p class="empty-state-description">No assets match the current filters.</p>
        </div>

        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th v-for="header in groupHeaders" :key="header" scope="col">{{ header }}</th>
                <th scope="col" class="report-table-num">Assets</th>
                <th scope="col">Operational status</th>
                <th scope="col">Asset kind</th>
                <th scope="col" class="report-table-num">Booked</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="index">
                <td
                  v-for="group in row.groups"
                  :key="group.dimension"
                  :class="group.is_unassigned ? 'report-cell-muted' : 'report-cell-strong'"
                >
                  {{ group.label }}
                </td>
                <td class="report-table-num report-cell-strong">{{ row.asset_count }}</td>
                <td>
                  <div class="report-chips">
                    <span v-if="row.by_operational_status.ready_for_field" class="report-chip">
                      Ready for Field <b>{{ row.by_operational_status.ready_for_field }}</b>
                    </span>
                    <span v-if="row.by_operational_status.under_maintenance" class="report-chip">
                      Under maint. <b>{{ row.by_operational_status.under_maintenance }}</b>
                    </span>
                    <span v-if="row.by_operational_status.down" class="report-chip">
                      Down <b>{{ row.by_operational_status.down }}</b>
                    </span>
                    <span v-if="row.by_operational_status.scraped" class="report-chip">
                      Scraped <b>{{ row.by_operational_status.scraped }}</b>
                    </span>
                    <span v-if="row.by_operational_status.under_inspection" class="report-chip">
                      Under insp. <b>{{ row.by_operational_status.under_inspection }}</b>
                    </span>
                    <span v-if="row.by_operational_status.lih" class="report-chip">
                      Lost in hole <b>{{ row.by_operational_status.lih }}</b>
                    </span>
                  </div>
                </td>
                <td>
                  <div class="report-chips">
                    <span v-if="row.by_asset_kind.standalone" class="report-chip">
                      Asset <b>{{ row.by_asset_kind.standalone }}</b>
                    </span>
                    <span v-if="row.by_asset_kind.package" class="report-chip">
                      Package <b>{{ row.by_asset_kind.package }}</b>
                    </span>
                    <span v-if="row.by_asset_kind.component" class="report-chip">
                      Component <b>{{ row.by_asset_kind.component }}</b>
                    </span>
                  </div>
                </td>
                <td class="report-table-num">{{ row.booked_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
