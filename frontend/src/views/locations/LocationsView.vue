<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import AssetLocationUpdateView from './AssetLocationUpdateView.vue'
import ManageLocationsView from './ManageLocationsView.vue'
import { useAuthStore } from '@/stores/auth.store'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const tabDefs = computed(() => {
  const tabs = [
    { key: 'asset-location-update', label: 'Asset Location Update' },
  ]
  if (auth.isAdmin) {
    tabs.push({ key: 'manage-locations', label: 'Manage Locations' })
  }
  return tabs
})

const activeTab = computed(() => {
  const q = route.query.tab as string | undefined
  if (q && tabDefs.value.some((t) => t.key === q)) return q
  return 'asset-location-update'
})

watch(activeTab, (newTab) => {
  if (route.query.tab !== newTab) {
    router.replace({ query: { tab: newTab } })
  }
})
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Locations</h1>
          <p class="page-subtitle">Track and manage physical asset locations</p>
        </div>
      </div>

      <nav class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >{{ tab.label }}</RouterLink>
      </nav>

      <template v-if="activeTab === 'asset-location-update'">
        <AssetLocationUpdateView />
      </template>
      <template v-else-if="activeTab === 'manage-locations' && auth.isAdmin">
        <ManageLocationsView />
      </template>
    </div>
  </AppLayout>
</template>
