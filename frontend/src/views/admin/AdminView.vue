<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import ListsView from '@/views/admin/ListsView.vue'
import PmRulesView from '@/views/pm-rules/PmRulesView.vue'
import UsersView from '@/views/admin/UsersView.vue'
import WoFormsView from '@/views/wo-forms/WoFormsView.vue'

const route = useRoute()

const activeTab = computed(() => {
  if (route.path.startsWith('/admin/pm-rules')) return 'pm-rules'
  if (route.path.startsWith('/admin/users')) return 'users'
  if (route.path.startsWith('/admin/wo-forms')) return 'wo-forms'
  return 'lists'
})

const tabs = [
  { key: 'lists', label: 'Lists & Dropdowns', to: '/admin/lists' },
  { key: 'pm-rules', label: 'PM Rules', to: '/admin/pm-rules' },
  { key: 'wo-forms', label: 'WO Forms', to: '/admin/wo-forms' },
  { key: 'users', label: 'Users & Access', to: '/admin/users' },
]
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Admin</h1>
          <p class="page-subtitle">Manage users, lookup lists, and preventive maintenance rules</p>
        </div>
      </div>

      <nav class="view-tabs">
        <RouterLink
          v-for="tab in tabs"
          :key="tab.key"
          :to="tab.to"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
          >{{ tab.label }}</RouterLink
        >
      </nav>

      <template v-if="activeTab === 'lists'">
        <ListsView />
      </template>
      <template v-else-if="activeTab === 'pm-rules'">
        <PmRulesView />
      </template>
      <template v-else-if="activeTab === 'wo-forms'">
        <WoFormsView />
      </template>
      <template v-else-if="activeTab === 'users'">
        <UsersView />
      </template>
    </div>
  </AppLayout>
</template>
