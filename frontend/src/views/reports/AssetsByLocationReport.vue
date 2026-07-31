<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  useAssetsByLocationReport,
  type AssetsByLocationFilters,
} from '@/composables/useAssetsByLocationReport'
import { useListOptions } from '@/composables/useListOptions'
import { toFaSubclassFilterOptions } from '@/lib/assetColumns'
import { ASSET_KIND_OPTIONS, OPERATIONAL_STATUS_OPTIONS } from '@/lib/reportOptions'
import type { AssetKind } from '@/types'

const ALL = '__all__'

const { loading, error, summary, rows, load } = useAssetsByLocationReport()
const { faSubclasses, loadFaSubclasses } = useListOptions()

const faSubclassOptions = computed(() => toFaSubclassFilterOptions(faSubclasses.value))

const faSubclassCode = ref<string>(ALL)
const assetKind = ref<string>(ALL)
const operationalStatus = ref<string>(ALL)
const includeInactive = ref(false)

function applyFilters() {
  const filters: AssetsByLocationFilters = {}
  if (faSubclassCode.value !== ALL) {
    filters.fa_subclass_code = faSubclassCode.value
  }
  if (assetKind.value !== ALL) {
    filters.asset_kind = assetKind.value as AssetKind
  }
  if (operationalStatus.value !== ALL) {
    filters.operational_status = operationalStatus.value
  }
  if (includeInactive.value) {
    filters.include_inactive = true
  }
  load(filters)
}

function clearFilters() {
  faSubclassCode.value = ALL
  assetKind.value = ALL
  operationalStatus.value = ALL
  includeInactive.value = false
  load()
}

onMounted(() => {
  loadFaSubclasses()
  load()
})
</script>

<template>
  <ReportPage
    title="Asset Distribution by Location"
    subtitle="Where assets are and how many sit at each location (R-2)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="abl-class">Asset class</Label>
          <Select v-model="faSubclassCode">
            <SelectTrigger id="abl-class"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All classes</SelectItem>
              <SelectItem v-for="opt in faSubclassOptions" :key="opt.value" :value="opt.value">
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

        <div class="report-filter report-filter-inline">
          <Label for="abl-inactive" class="report-filter-check">
            <Checkbox id="abl-inactive" v-model="includeInactive" />
            Include deactivated assets
          </Label>
        </div>

        <div class="report-filter-actions">
          <Button variant="outline" :disabled="loading" @click="clearFilters">Clear</Button>
          <Button :disabled="loading" @click="applyFilters">Apply</Button>
        </div>
      </div>
    </template>

    <template #summary>
      <ReportSummaryStats
        v-if="summary"
        :stats="[
          { label: 'Total assets', value: summary.total_assets },
          { label: 'Locations', value: summary.total_locations },
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
                <th scope="col">Location</th>
                <th scope="col" class="report-table-num">Assets</th>
                <th scope="col">Operational status</th>
                <th scope="col">Asset kind</th>
                <th scope="col" class="report-table-num">Booked</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.location_id ?? 'unassigned'">
                <td :class="row.is_unassigned ? 'report-cell-muted' : 'report-cell-strong'">
                  {{ row.location_name }}
                </td>
                <td class="report-table-num report-cell-strong">{{ row.asset_count }}</td>
                <td>
                  <div class="report-chips">
                    <span v-if="row.by_operational_status.active" class="report-chip">
                      Active <b>{{ row.by_operational_status.active }}</b>
                    </span>
                    <span v-if="row.by_operational_status.under_maintenance" class="report-chip">
                      Under maint. <b>{{ row.by_operational_status.under_maintenance }}</b>
                    </span>
                    <span v-if="row.by_operational_status.down" class="report-chip">
                      Down <b>{{ row.by_operational_status.down }}</b>
                    </span>
                    <span v-if="row.by_operational_status.inactive" class="report-chip">
                      Inactive <b>{{ row.by_operational_status.inactive }}</b>
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
