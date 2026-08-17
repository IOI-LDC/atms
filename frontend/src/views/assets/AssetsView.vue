<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AppDataTable from '@/components/app/AppDataTable.vue'
import AssetIdentity from '@/components/app/AssetIdentity.vue'
import IdentityFilters from '@/components/app/IdentityFilters.vue'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuthStore } from '@/stores/auth.store'
import { useAssets } from '@/composables/useAssets'
import { useListOptions } from '@/composables/useListOptions'
import { useIdentityFilters } from '@/composables/useIdentityFilters'
import {
  assetColumns,
  assetFilterOptions,
  toMaintenanceCategoryFilterOptions,
} from '@/lib/assetColumns'
import type { Asset } from '@/types'
import {
  operationalStatusClass,
  operationalStatusLabel,
  assetKindClass,
  assetKindLabel,
} from '@/lib/displayHelpers'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const { all, locations, loadLocations } = useAssets()
const { maintenanceCategories, loadMaintenanceCategories, assetConditions, loadAssetConditions } =
  useListOptions()

// Static filter options + the live maintenance-category list (readable by every
// role, unlike the Admin/Manager-gated location filter above).
const mergedFilterOptions = computed(() => ({
  ...assetFilterOptions,
  'maintenance_category.name': toMaintenanceCategoryFilterOptions(maintenanceCategories.value),
  // Live data like the categories above — Admins add and retire conditions
  // through the Lists screen, so a hardcoded list would drift.
  condition_status: assetConditions.value,
}))

// ── Tabs ──────────────────────────────────────────────────────────────────────

const tabDefs = [
  { key: 'all-assets', label: 'All Assets' },
  { key: 'asset-assembly', label: 'Asset Assembly' },
]

const activeTab = computed(() => {
  const t = route.query.tab as string | undefined
  return tabDefs.some((d) => d.key === t) ? t! : 'all-assets'
})

watch(activeTab, (tab) => {
  if (tab && route.query.tab !== tab) router.replace({ path: route.path, query: { tab } })
})

watch(
  activeTab,
  (tab) => {
    if (tab === 'all-assets') {
      all.load()
      if (auth.isAdminOrManager || auth.isLogistics) loadLocations()
      loadMaintenanceCategories()
      loadAssetConditions()
    }
  },
  { immediate: true },
)

// ── Location filter (Admin/Manager/Logistics) ─────────────────────────────────
// Pre-filters rows before passing to AppDataTable, which then handles search /
// column filters / sort / pagination in-memory on top of this subset. The
// select itself renders inside the table's #toolbar slot, beside the search.

const locationFilter = ref<number | null>(null)

const locationFilteredRows = computed<Asset[]>(() => {
  if (!locationFilter.value) return all.rows.value
  return all.rows.value.filter((a) => a.current_location?.id === locationFilter.value)
})

// Serial / Size / Category filters live in the toolbar, not as columns — the
// identity renders as one package and duplicating a value purely to filter it
// was explicitly ruled out.
const identityFilters = useIdentityFilters(locationFilteredRows)
const filteredRows = identityFilters.filteredRows

// ── Navigation ────────────────────────────────────────────────────────────────

function goDetail(payload: { row: Asset }) {
  router.push(`/assets/${payload.row.id}`)
}
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Asset Management</h1>
          <p class="page-subtitle">Track and manage the operational asset registry</p>
        </div>
        <div class="page-actions">
          <!-- Add Asset is scoped to Phase 2 create flow; placeholder until built -->
          <Button v-if="auth.isAdminOrManager" disabled aria-label="Add asset — coming soon">
            Add Asset
          </Button>
        </div>
      </div>

      <div class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ path: '/assets', query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >
          {{ tab.label }}
          <span v-if="tab.key === 'asset-assembly'" class="view-tab-badge">Phase 2</span>
        </RouterLink>
      </div>

      <!-- ── All Assets tab ─────────────────────────────────────────────── -->
      <template v-if="activeTab === 'all-assets'">
        <AppDataTable
          :key="activeTab"
          :rows="filteredRows"
          :columns="assetColumns"
          :filter-options="mergedFilterOptions"
          empty-text="No assets found."
          label="Assets"
          :loading="all.loading.value"
          @row-click="goDetail"
        >
          <template #toolbar>
            <IdentityFilters
              v-model:serial="identityFilters.serialQuery.value"
              v-model:size="identityFilters.sizeValue.value"
              v-model:category="identityFilters.categoryValue.value"
              :size-options="identityFilters.sizeOptions.value"
              :category-options="identityFilters.categoryOptions.value"
            />
            <!-- Location filter — Admin/Manager/Logistics only (viewAny-gated) -->
            <Select
              v-if="auth.isAdminOrManager || auth.isLogistics"
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
                <SelectItem v-for="loc in locations" :key="loc.id" :value="String(loc.id)">{{
                  loc.name
                }}</SelectItem>
              </SelectContent>
            </Select>
          </template>
          <template #cell="{ column, row }">
            <span v-if="column.field === 'asset_tag'" class="atms-erp-code">
              {{ row.asset_tag ?? '—' }}
            </span>

            <span v-else-if="column.field === 'name'" class="table-cell-stack">
              <AssetIdentity :asset="row" stacked>
                <span v-if="row.is_booked" class="status-badge status-booked">Booked</span>
              </AssetIdentity>
            </span>

            <span v-else-if="column.field === 'maintenance_category.name'">
              {{ row.maintenance_category?.name ?? '—' }}
            </span>

            <span v-else-if="column.field === 'asset_kind'" :class="assetKindClass(row.asset_kind)">
              {{ assetKindLabel(row.asset_kind) }}
            </span>

            <span
              v-else-if="column.field === 'operational_status'"
              :class="operationalStatusClass(row.operational_status)"
            >
              {{ operationalStatusLabel(row.operational_status) }}
            </span>

            <!-- Plain text, not a badge: Condition is a note about the asset,
                 and a second pill beside Operational Status would compete with
                 it for the reader's attention without ranking above it. -->
            <span v-else-if="column.field === 'condition_status'">
              {{ row.condition_label ?? row.condition_status ?? '—' }}
            </span>

            <span v-else-if="column.field === 'current_location'">
              {{ row.current_location?.name ?? '—' }}
            </span>
          </template>
        </AppDataTable>
      </template>

      <!-- ── Asset Assembly tab (Phase 2) ──────────────────────────────── -->
      <div v-else-if="activeTab === 'asset-assembly'" class="data-card">
        <div class="data-card-header">
          <h2 class="data-card-title">Asset Assembly</h2>
          <span class="status-badge status-inactive">Planned — Phase 2</span>
        </div>
        <div class="data-card-content">
          <div class="empty-state">
            <p class="empty-state-title">Coming in Phase 2</p>
            <p class="empty-state-description">
              Asset Assembly will let you manage package and component hierarchies, install and
              remove components, and track the full assembly and service history for each component
              — all from a single view.
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
