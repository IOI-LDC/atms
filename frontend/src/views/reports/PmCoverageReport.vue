<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import ReportLoadMore from '@/components/app/ReportLoadMore.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { usePmCoverageReport, type PmCoverageFilters } from '@/composables/usePmCoverageReport'
import { useLocations } from '@/composables/useLocations'
import {
  fmtKpiPercent,
  operationalStatusClass,
  operationalStatusLabel,
} from '@/lib/displayHelpers'
import { ASSET_KIND_OPTIONS } from '@/lib/reportOptions'
import type { AssetKind } from '@/types'

const ALL = '__all__'

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } = usePmCoverageReport()
const { activeLocations, loadLocations } = useLocations()

const locationId = ref<string>(ALL)
const assetKind = ref<string>(ALL)

function applyFilters() {
  const filters: PmCoverageFilters = {}
  if (locationId.value !== ALL) {
    filters.location_id = locationId.value
  }
  if (assetKind.value !== ALL) {
    filters.asset_kind = assetKind.value as AssetKind
  }
  load(filters)
}

function clearFilters() {
  locationId.value = ALL
  assetKind.value = ALL
  load()
}

onMounted(() => {
  loadLocations()
  load()
})
</script>

<template>
  <ReportPage
    title="PM Coverage / Gaps"
    subtitle="Active assets with no active PM assignment (R-9)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="cov-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="cov-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="cov-kind">Asset kind</Label>
          <Select v-model="assetKind">
            <SelectTrigger id="cov-kind"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All kinds</SelectItem>
              <SelectItem v-for="o in ASSET_KIND_OPTIONS" :key="o.value" :value="o.value">
                {{ o.label }}
              </SelectItem>
            </SelectContent>
          </Select>
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
          { label: 'Active assets', value: summary.total_assets },
          { label: 'Covered', value: summary.covered_assets },
          { label: 'Uncovered', value: summary.uncovered_assets },
          { label: 'Coverage', value: fmtKpiPercent(summary.coverage_pct) },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading coverage…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">Full coverage</p>
          <p class="empty-state-description">Every active asset has an active PM assignment.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} uncovered {{ rows.length === 1 ? 'asset' : 'assets' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Asset</th>
                  <th scope="col">Tag</th>
                  <th scope="col">Class</th>
                  <th scope="col">Location</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td>
                    <RouterLink :to="`/assets/${row.id}`" class="report-link">{{ row.name }}</RouterLink>
                    <span class="report-cell-muted"> · {{ row.erp_asset_code }}</span>
                  </td>
                  <td class="report-cell-muted">{{ row.asset_tag ?? '—' }}</td>
                  <td class="report-cell-muted">{{ row.fa_subclass_code ?? '—' }}</td>
                  <td :class="row.current_location ? '' : 'report-cell-muted'">
                    {{ row.current_location?.name ?? 'Unassigned' }}
                  </td>
                  <td>
                    <span :class="operationalStatusClass(row.operational_status)">
                      {{ operationalStatusLabel(row.operational_status) }}
                    </span>
                  </td>
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
