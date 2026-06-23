<script setup lang="ts">
import AppLayout from '@/components/app/AppLayout.vue'
import { useDashboard } from '@/composables/useDashboard'

const { data, loading, error } = useDashboard()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div>
          <h1 class="page-title">Dashboard</h1>
          <p class="page-subtitle">Overview of maintenance operations</p>
        </div>
      </div>

      <div v-if="loading" class="loading-state">Loading…</div>
      <div v-else-if="error" class="error-state" role="alert">{{ error }}</div>

      <template v-else-if="data">
        <div class="kpi-grid">
          <div class="kpi-card">
            <p class="kpi-card-title">Pending Requests</p>
            <p class="kpi-card-value">{{ data.summary.pending_maintenance_requests }}</p>
          </div>
          <div class="kpi-card">
            <p class="kpi-card-title">Open Work Orders</p>
            <p class="kpi-card-value">{{ data.summary.open_work_orders }}</p>
          </div>
          <div class="kpi-card">
            <p class="kpi-card-title">Overdue PM Rules</p>
            <p class="kpi-card-value">{{ data.summary.overdue_pm_rules }}</p>
          </div>
          <div class="kpi-card">
            <p class="kpi-card-title">Recently Closed</p>
            <p class="kpi-card-value">{{ data.summary.recently_closed_work_orders }}</p>
          </div>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
