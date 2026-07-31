<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import UpdateLocationSheet from '@/components/locations/UpdateLocationSheet.vue'
import LocationHistorySheet from '@/components/locations/LocationHistorySheet.vue'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useLocations } from '@/composables/useLocations'
import {
  locationTypeClass,
  assetMaintenanceStatusClass,
  assetMaintenanceStatusLabel,
} from '@/lib/displayHelpers'
import { MapPin, History } from '@lucide/vue'
import type { AppColumnDef } from '@/lib/appTable'
import type { Asset } from '@/types'

const {
  activeLocations,
  locationsLoading,
  locationsError,
  loadLocations,
  assets,
  assetsLoading,
  loadAssets,
} = useLocations()

// ── Column definitions ────────────────────────────────────────────────────────
const columns: AppColumnDef<Asset>[] = [
  { field: 'asset_tag', header: 'Asset Tag', sortable: false, minWidth: 200 },
  { field: 'name', header: 'Name', sortable: true },
  {
    field: 'current_location',
    header: 'Current Location',
    sortable: false,
    minWidth: 100,
    align: 'center',
  },
  { field: 'maintenance_status', header: 'Status', sortable: true, minWidth: 100, align: 'center' },
  { field: 'actions', header: '', sortable: false, minWidth: 100, align: 'center' },
]

// ── Location filter ───────────────────────────────────────────────────────────
const locationFilter = ref<number | null>(null)

const filteredAssets = computed<Asset[]>(() => {
  if (!locationFilter.value) return assets.value
  return assets.value.filter((a) => a.current_location?.id === locationFilter.value)
})

// ── Initial load ──────────────────────────────────────────────────────────────
onMounted(() => {
  loadLocations()
  loadAssets()
})

// ── Update Location sheet ─────────────────────────────────────────────────────
const selectedAsset = ref<Asset | null>(null)
const sheetOpen = ref(false)

function openSheet(asset: Asset) {
  selectedAsset.value = asset
  sheetOpen.value = true
}

function onClose() {
  sheetOpen.value = false
  selectedAsset.value = null
}

function onSaved() {
  loadAssets(true)
}

// ── Location History sheet ────────────────────────────────────────────────────
const historyAsset = ref<Asset | null>(null)
const historyOpen = ref(false)

function openHistory(asset: Asset) {
  historyAsset.value = asset
  historyOpen.value = true
}

function onCloseHistory() {
  historyOpen.value = false
  historyAsset.value = null
}
</script>

<template>
  <div class="page-content">
    <div v-if="locationsError" class="error-state" role="alert">
      {{ locationsError }}
    </div>

    <AppDataTable
      :rows="filteredAssets"
      :columns="columns"
      empty-text="No active assets found."
      label="Assets"
      :loading="assetsLoading"
    >
      <!-- Location filter (only shown when locations are available) -->
      <template v-if="activeLocations.length > 0" #toolbar>
        <Select
          :model-value="locationFilter !== null ? String(locationFilter) : '__all__'"
          @update:model-value="
            (v) => {
              locationFilter = v === '__all__' ? null : Number(v)
            }
          "
        >
          <SelectTrigger class="asset-location-filter" aria-label="Filter by location">
            <SelectValue placeholder="All locations" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="__all__">All locations</SelectItem>
            <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">{{
              loc.name
            }}</SelectItem>
          </SelectContent>
        </Select>
      </template>

      <template #cell="{ column, row }">
        <RouterLink
          v-if="column.field === 'asset_tag'"
          :to="`/assets/${row.id}`"
          class="table-link"
          >{{ row.asset_tag ?? '—' }}</RouterLink
        >

        <RouterLink
          v-else-if="column.field === 'name'"
          :to="`/assets/${row.id}`"
          class="table-link"
          >{{ row.name }}</RouterLink
        >

        <!-- Location with type badge -->
        <span
          v-else-if="column.field === 'current_location'"
          :class="row.current_location ? locationTypeClass(row.current_location.type) : ''"
          >{{ row.current_location?.name ?? '—' }}</span
        >

        <!-- Maintenance status badge -->
        <span
          v-else-if="column.field === 'maintenance_status'"
          :class="assetMaintenanceStatusClass(row.maintenance_status)"
          >{{ assetMaintenanceStatusLabel(row.maintenance_status) }}</span
        >

        <!-- Per-row actions -->
        <div v-else-if="column.field === 'actions'" class="table-row-actions">
          <Button
            variant="outline"
            size="icon-sm"
            :aria-label="`Update location for ${row.name}`"
            @click="openSheet(row)"
          >
            <MapPin />
          </Button>
          <Button
            variant="ghost"
            size="icon-sm"
            :aria-label="`View location history for ${row.name}`"
            @click="openHistory(row)"
          >
            <History />
          </Button>
        </div>
      </template>
    </AppDataTable>

    <!-- Update Location Sheet -->
    <UpdateLocationSheet
      v-if="selectedAsset"
      :asset="selectedAsset"
      :locations="activeLocations"
      :open="sheetOpen"
      @close="onClose"
      @saved="onSaved"
    />

    <!-- Location History Sheet -->
    <LocationHistorySheet
      v-if="historyAsset"
      :asset="historyAsset"
      :open="historyOpen"
      @close="onCloseHistory"
    />
  </div>
</template>
