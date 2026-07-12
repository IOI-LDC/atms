<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import AppLayout from '@/components/app/AppLayout.vue'
import UpdateLocationSheet from '@/components/locations/UpdateLocationSheet.vue'
import AssetMoveLocator from '@/components/locations/AssetMoveLocator.vue'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useLocations } from '@/composables/useLocations'
import { useAssetMove } from '@/composables/useAssetMove'
import { useRecentMoves } from '@/composables/useRecentMoves'
import { useLocationAssets } from '@/composables/useLocationAssets'
import { assetKindLabel, assetKindClass, fmtDate } from '@/lib/displayHelpers'
import { MapPin, TriangleAlert } from '@lucide/vue'

const { activeLocations, loadLocations } = useLocations()
const { recentMoves, recentLoading, loadRecentMoves } = useRecentMoves()
const { locationAssets, locationAssetsLoading, loadLocationAssets, clearLocationAssets } =
  useLocationAssets()

// Location scope ("All locations" = null).
const locationId = ref<number | null>(null)

const selectedLocationName = computed(
  () => activeLocations.value.find((l) => l.id === locationId.value)?.name ?? '',
)

const { selectedAsset, sheetOpen, openMove, closeMove, onSaved } = useAssetMove(() => {
  loadRecentMoves()
  if (locationId.value) loadLocationAssets(locationId.value)
})

// Picking a location lists its assets; clearing it hides the list.
watch(locationId, (id) => {
  if (id) {
    loadLocationAssets(id)
  } else {
    clearLocationAssets()
  }
})

onMounted(() => {
  loadLocations()
  loadRecentMoves()
})
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Update Asset Location</h1>
          <p class="page-subtitle">Find an asset and record where it is now.</p>
        </div>
      </div>

      <!-- Narrow by location, then browse its assets or search directly. -->
      <div class="filter-bar move-filter-bar">
        <div class="move-filter-field">
          <Label for="move-location">Location</Label>
          <Select
            :model-value="locationId !== null ? String(locationId) : '__all__'"
            @update:model-value="
              (v) => {
                locationId = v === '__all__' ? null : Number(v)
              }
            "
          >
            <SelectTrigger
              id="move-location"
              class="move-loc-select"
              aria-label="Filter by location"
            >
              <SelectValue placeholder="All locations" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All locations</SelectItem>
              <SelectItem v-for="loc in activeLocations" :key="loc.id" :value="String(loc.id)">{{
                loc.name
              }}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div class="move-filter-field move-filter-asset">
          <Label for="move-asset">Find asset</Label>
          <AssetMoveLocator input-id="move-asset" :location-id="locationId" @select="openMove" />
        </div>
      </div>

      <!-- Assets at the selected location -->
      <section v-if="locationId" class="move-results">
        <div class="move-results-label">
          Assets at {{ selectedLocationName }}
          <span class="move-results-count">{{ locationAssets.length }}</span>
        </div>

        <div v-if="locationAssetsLoading" class="loading-state">Loading…</div>
        <div v-else-if="locationAssets.length === 0" class="empty-state">
          <p class="empty-state-title">No assets here</p>
          <p class="empty-state-description">No active assets are at this location.</p>
        </div>
        <div v-else class="move-list">
          <article v-for="asset in locationAssets" :key="asset.id" class="move-card">
            <div class="move-card-main">
              <div class="move-card-tag">{{ asset.asset_tag ?? asset.erp_asset_code }}</div>
              <div class="move-card-name">{{ asset.name }}</div>
              <div class="move-card-attrs">
                <span v-if="asset.serial_number" class="move-card-attr">
                  <span class="move-card-attr-key">SN</span> {{ asset.serial_number }}
                </span>
                <span
                  v-if="asset.asset_kind"
                  :class="['status-badge', assetKindClass(asset.asset_kind)]"
                  >{{ assetKindLabel(asset.asset_kind) }}</span
                >
              </div>
              <p v-if="asset.child_assets_count" class="move-card-kids">
                <TriangleAlert class="move-card-kids-icon" aria-hidden="true" />
                Has {{ asset.child_assets_count }} component{{
                  asset.child_assets_count > 1 ? 's' : ''
                }}
                — move them separately for now (cascade arrives in Phase 2).
              </p>
            </div>
            <Button
              class="move-card-action"
              :aria-label="`Move ${asset.name} to a new location`"
              @click="openMove(asset)"
            >
              <MapPin />
              Move to new location
            </Button>
          </article>
        </div>
      </section>

      <!-- No location chosen: guide the user -->
      <div v-else class="empty-state">
        <p class="empty-state-title">Find an asset to move</p>
        <p class="empty-state-description">
          Choose a location to list its assets, or search for an asset above.
        </p>
      </div>

      <!-- Recently moved -->
      <section class="move-recent">
        <div class="move-results-label">Recently moved</div>
        <div v-if="recentLoading" class="loading-state">Loading…</div>
        <div v-else-if="recentMoves.length === 0" class="empty-state">
          <p class="empty-state-title">No recent moves</p>
          <p class="empty-state-description">Location changes will appear here as they happen.</p>
        </div>
        <div v-else class="move-recent-list">
          <div v-for="m in recentMoves" :key="m.id" class="move-recent-row">
            <span class="move-recent-tag">{{ m.asset.asset_tag || m.asset.erp_asset_code }}</span>
            <span class="move-recent-route">
              {{ m.asset.name }} <span class="move-recent-arrow">→</span>
              <b>{{ m.to_location?.name ?? '—' }}</b>
            </span>
            <time class="move-recent-date">{{ fmtDate(m.effective_at) }}</time>
          </div>
        </div>
      </section>
    </div>

    <!-- Reuse the existing move sheet (current location, picker, reason/notes, history, confirm). -->
    <UpdateLocationSheet
      v-if="selectedAsset"
      :asset="selectedAsset"
      :locations="activeLocations"
      :open="sheetOpen"
      @close="closeMove"
      @saved="onSaved"
    />
  </AppLayout>
</template>
