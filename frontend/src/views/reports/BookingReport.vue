<script setup lang="ts">
import { ref, onMounted } from 'vue'
import ReportPage from '@/components/app/ReportPage.vue'
import ReportSummaryStats from '@/components/app/ReportSummaryStats.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useBookingReport, type BookingFilters } from '@/composables/useBookingReport'
import { useLocations } from '@/composables/useLocations'
import { ASSET_KIND_OPTIONS } from '@/lib/reportOptions'
import type { AssetKind } from '@/types'

const ALL = '__all__'

const { loading, error, summary, rows, load } = useBookingReport()
const { activeLocations, loadLocations } = useLocations()

const locationId = ref<string>(ALL)
const assetKind = ref<string>(ALL)

function applyFilters() {
  const filters: BookingFilters = {}
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
    title="Asset Booking / Availability"
    subtitle="Booked vs freely-available assets, by location (R-13)."
  >
    <template #filters>
      <div class="report-filters">
        <div class="report-filter">
          <Label for="bk-location">Location</Label>
          <Select v-model="locationId">
            <SelectTrigger id="bk-location"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem :value="ALL">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">
                {{ loc.name }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div class="report-filter">
          <Label for="bk-kind">Asset kind</Label>
          <Select v-model="assetKind">
            <SelectTrigger id="bk-kind"><SelectValue /></SelectTrigger>
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
          { label: 'Total assets', value: summary.total_assets },
          { label: 'Booked', value: summary.booked_count },
          { label: 'Available', value: summary.available_count },
        ]"
      />
    </template>

    <div class="data-card">
      <div class="data-card-content">
        <div v-if="error" class="error-state" role="alert">{{ error }}</div>
        <div v-else-if="loading" class="loading-state">Loading booking…</div>
        <div v-else-if="rows.length === 0" class="empty-state">
          <p class="empty-state-title">No assets</p>
          <p class="empty-state-description">No assets match the current filters.</p>
        </div>
        <div v-else class="report-table-wrap">
          <table class="report-table">
            <thead>
              <tr>
                <th scope="col">Location</th>
                <th scope="col" class="report-table-num">Total</th>
                <th scope="col" class="report-table-num">Booked</th>
                <th scope="col" class="report-table-num">Available</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.location_id ?? 'unassigned'">
                <td :class="row.location_name ? 'report-cell-strong' : 'report-cell-muted'">
                  {{ row.location_name ?? 'Unassigned' }}
                </td>
                <td class="report-table-num report-cell-strong">{{ row.total_count }}</td>
                <td class="report-table-num">{{ row.booked_count }}</td>
                <td class="report-table-num">{{ row.available_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </ReportPage>
</template>
