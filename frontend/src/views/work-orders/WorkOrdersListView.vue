<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { useAuthStore } from '@/stores/auth.store'
import { useWorkOrders } from '@/composables/useWorkOrders'
import { woStatusClass, woStatusLabel, priorityClass, priorityLabel, fmtDate } from '@/lib/displayHelpers'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const { myWo, loadMyWorkOrders, all, loadAll, active, loadActive, completed, loadCompleted, closed, loadClosed } = useWorkOrders()

// ── Tabs (role-based) ─────────────────────────────────────────────────────────

const tabDefs = computed(() => {
  const tabs: { key: string; label: string }[] = []
  if (auth.isTechnician) tabs.push({ key: 'my-work-orders', label: 'My Work Orders' })
  if (auth.isAdminOrManager) tabs.push({ key: 'all', label: 'All' })
  tabs.push({ key: 'active', label: 'Active' })
  tabs.push({ key: 'completed', label: 'Completed' })
  tabs.push({ key: 'closed', label: 'Closed' })
  return tabs
})

const activeTab = computed(() => {
  const t = route.query.tab as string | undefined
  return tabDefs.value.some(d => d.key === t) ? t! : (tabDefs.value[0]?.key ?? 'active')
})

watch(activeTab, (tab) => {
  if (tab && route.query.tab !== tab) router.replace({ path: route.path, query: { tab } })
  if (tab === 'my-work-orders') loadMyWorkOrders()
  if (tab === 'all')            loadAll()
  if (tab === 'active')         loadActive()
  if (tab === 'completed')      loadCompleted()
  if (tab === 'closed')         loadClosed()
}, { immediate: true })
</script>

<template>
  <AppLayout>
    <div class="page-section">

      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Work Orders</h1>
          <p class="page-subtitle">Track and manage maintenance work orders</p>
        </div>
      </div>

      <div class="view-tabs">
        <RouterLink
          v-for="tab in tabDefs"
          :key="tab.key"
          :to="{ path: '/work-orders', query: { tab: tab.key } }"
          :class="['view-tab', activeTab === tab.key ? 'view-tab-active' : 'view-tab-normal']"
        >
          {{ tab.label }}
          <span v-if="tab.key === 'completed' && completed.items.value.length > 0" class="view-tab-count">
            {{ completed.items.value.length }}
          </span>
        </RouterLink>
      </div>

      <!-- ── My Work Orders (Technician) ── -->
      <template v-if="activeTab === 'my-work-orders'">
        <div v-if="myWo.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="myWo.error.value" class="error-state">{{ myWo.error.value }}</div>
        <template v-else>
          <div v-if="myWo.items.value.length === 0" class="empty-state">No work orders assigned to you.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Asset</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Started</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in myWo.items.value" :key="item.id" class="table-row-clickable" @click="router.push(`/work-orders/${item.id}`)">
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td><span :class="woStatusClass(item.status)">{{ woStatusLabel(item.status) }}</span></td>
                  <td>{{ fmtDate(item.started_at) }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="myWo.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="myWo.loadingMore.value" @click="loadMyWorkOrders(true)">
              {{ myWo.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── All ── -->
      <template v-else-if="activeTab === 'all'">
        <div v-if="all.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="all.error.value" class="error-state">{{ all.error.value }}</div>
        <template v-else>
          <div v-if="all.items.value.length === 0" class="empty-state">No work orders found.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Asset</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned To</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in all.items.value" :key="item.id" class="table-row-clickable" @click="router.push(`/work-orders/${item.id}`)">
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td><span :class="woStatusClass(item.status)">{{ woStatusLabel(item.status) }}</span></td>
                  <td>{{ item.assigned_to?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="all.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="all.loadingMore.value" @click="loadAll(true)">
              {{ all.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── Active ── -->
      <template v-else-if="activeTab === 'active'">
        <div v-if="active.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="active.error.value" class="error-state">{{ active.error.value }}</div>
        <template v-else>
          <div v-if="active.items.value.length === 0" class="empty-state">No active work orders.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Asset</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Assigned To</th>
                  <th>Started</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in active.items.value" :key="item.id" class="table-row-clickable" @click="router.push(`/work-orders/${item.id}`)">
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td><span :class="woStatusClass(item.status)">{{ woStatusLabel(item.status) }}</span></td>
                  <td>{{ item.assigned_to?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.started_at) }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="active.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="active.loadingMore.value" @click="loadActive(true)">
              {{ active.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── Completed ── -->
      <template v-else-if="activeTab === 'completed'">
        <div v-if="completed.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="completed.error.value" class="error-state">{{ completed.error.value }}</div>
        <template v-else>
          <div v-if="completed.items.value.length === 0" class="empty-state">No work orders awaiting closure.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Asset</th>
                  <th>Priority</th>
                  <th>Assigned To</th>
                  <th>Completed</th>
                  <th>Created</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in completed.items.value" :key="item.id" class="table-row-clickable" @click="router.push(`/work-orders/${item.id}`)">
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td>{{ item.assigned_to?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.completed_at) }}</td>
                  <td>{{ fmtDate(item.created_at) }}</td>
                  <td class="table-cell-actions">
                    <Button size="sm" @click.stop="router.push(`/work-orders/${item.id}`)">Review & Close</Button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="completed.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="completed.loadingMore.value" @click="loadCompleted(true)">
              {{ completed.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

      <!-- ── Closed ── -->
      <template v-else-if="activeTab === 'closed'">
        <div v-if="closed.loading.value" class="loading-state">Loading…</div>
        <div v-else-if="closed.error.value" class="error-state">{{ closed.error.value }}</div>
        <template v-else>
          <div v-if="closed.items.value.length === 0" class="empty-state">No closed work orders yet.</div>
          <div v-else class="table-container">
            <table class="dense-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Asset</th>
                  <th>Priority</th>
                  <th>Assigned To</th>
                  <th>Closed</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in closed.items.value" :key="item.id" class="table-row-clickable" @click="router.push(`/work-orders/${item.id}`)">
                  <td class="table-cell-mono">{{ item.number }}</td>
                  <td>
                    <div class="table-cell-primary">{{ item.asset.name }}</div>
                    <div class="table-cell-secondary">{{ item.asset.erp_asset_code }}</div>
                  </td>
                  <td><span :class="priorityClass(item.priority)">{{ priorityLabel(item.priority) }}</span></td>
                  <td>{{ item.assigned_to?.name ?? '—' }}</td>
                  <td>{{ fmtDate(item.closed_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="closed.nextCursor.value" class="load-more-row">
            <Button variant="outline" size="sm" :disabled="closed.loadingMore.value" @click="loadClosed(true)">
              {{ closed.loadingMore.value ? 'Loading…' : 'Load more' }}
            </Button>
          </div>
        </template>
      </template>

    </div>
  </AppLayout>
</template>
