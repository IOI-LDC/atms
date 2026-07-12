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
  useAssetMovementReport,
  type AssetMovementFilters,
} from '@/composables/useAssetMovementReport'
import { useLocations } from '@/composables/useLocations'
import { fmtDateTime } from '@/lib/displayHelpers'
import { reportDateWindow } from '@/lib/reportOptions'

const ALL = '__all__'
const DEFAULT = reportDateWindow(90)

const { rows, summary, loading, loadingMore, error, hasMore, load, loadMore } =
  useAssetMovementReport()
const { activeLocations, loadLocations } = useLocations()

const fromDate = ref(DEFAULT.from)
const toDate = ref(DEFAULT.to)
const selectedAsset = ref<{ id: number; label: string } | null>(null)
const fromLocationId = ref<string>(ALL)
const toLocationId = ref<string>(ALL)

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
  const filters: AssetMovementFilters = { from: fromDate.value, to: toDate.value }
  if (selectedAsset.value) {
    filters.asset_id = selectedAsset.value.id
  }
  if (fromLocationId.value !== ALL) {
    filters.from_location_id = fromLocationId.value
  }
  if (toLocationId.value !== ALL) {
    filters.to_location_id = toLocationId.value
  }
  load(filters)
}

function clearFilters() {
  fromDate.value = DEFAULT.from
  toDate.value = DEFAULT.to
  selectedAsset.value = null
  fromLocationId.value = ALL
  toLocationId.value = ALL
  load({ from: DEFAULT.from, to: DEFAULT.to })
}

onMounted(() => {
  loadLocations()
  load({ from: DEFAULT.from, to: DEFAULT.to })
})
</script>

<template>
  <ReportPage
    title="Asset Movement Log"
    subtitle="Relocations in the period, by from → to route (R-18). Read-only AM data."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="mv-from">From date</Label>
          <DatePicker id="mv-from" v-model="fromDate" :max="toDate || todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mv-to">To date</Label>
          <DatePicker id="mv-to" v-model="toDate" :min="fromDate" :max="todayStr" />
        </div>
        <div class="report-filter">
          <Label for="mv-asset">Asset</Label>
          <AssetCombobox v-model="selectedAsset" input-id="mv-asset" />
        </div>
        <div class="report-filter">
          <Label for="mv-fromloc">Source</Label>
          <Select v-model="fromLocationId">
            <SelectTrigger id="mv-fromloc"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">Any source</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="mv-toloc">Destination</Label>
          <Select v-model="toLocationId">
            <SelectTrigger id="mv-toloc"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">Any destination</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
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
          { label: 'Movements', value: summary.total_movements },
          { label: 'Assets moved', value: summary.unique_assets_moved },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading && rows.length === 0" class="loading-state">Loading movements…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No movements</p>
          <p class="empty-state-description">No relocations recorded in the selected window.</p>
        </div>
        <template v-else>
          <p class="report-result-meta">
            Showing {{ rows.length }} {{ rows.length === 1 ? 'movement' : 'movements' }}
            <span v-if="hasMore">· more available</span>
          </p>
          <div class="report-table-wrap">
            <table class="report-table">
              <thead>
                <tr>
                  <th scope="col">Moved at</th>
                  <th scope="col">Asset</th>
                  <th scope="col">From</th>
                  <th scope="col">To</th>
                  <th scope="col">Reason</th>
                  <th scope="col">Notes</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td class="report-cell-muted">{{ fmtDateTime(row.effective_at) }}</td>
                  <td>
                    <RouterLink :to="`/assets/${row.asset.id}`" class="report-link">
                      {{ row.asset.name }}
                    </RouterLink>
                    <span class="report-cell-muted"> · {{ row.asset.erp_asset_code }}</span>
                  </td>
                  <td :class="row.from_location ? '' : 'report-cell-muted'">
                    {{ row.from_location?.name ?? 'New' }}
                  </td>
                  <td class="report-cell-strong">{{ row.to_location?.name ?? '—' }}</td>
                  <td class="report-cell-muted">{{ row.reason ?? '—' }}</td>
                  <td class="report-cell-muted">{{ row.notes ?? '—' }}</td>
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
