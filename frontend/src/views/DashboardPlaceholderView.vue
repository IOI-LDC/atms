<script setup lang="ts">
import { computed } from 'vue'
import { ArrowRight, CalendarClock, ClipboardList, Gauge, Wrench } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import { Button } from '@/components/ui/button'
import PendingRequirementPlaceholder from '@/components/app/PendingRequirementPlaceholder.vue'
import { useDashboard } from '@/composables/useDashboard'
import { useQuickActions } from '@/composables/useQuickActions'

const {
  data: dashData,
  loading,
  error,
  showPendingMr,
  showOpenWo,
  showOverduePm,
} = useDashboard()

const { actions } = useQuickActions(['New MR', 'Locations'])

const hasAnyCard = computed(
  () => showPendingMr.value || showOpenWo.value || showOverduePm.value,
)
const countsLoading = computed(() => loading.value && !dashData.value)
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Dashboard</h1>
          <p class="page-subtitle">Interim view — the full dashboard is pending LDC’s requirements.</p>
        </div>
        <div v-if="actions.length > 0" class="page-actions">
          <Button
            v-for="action in actions"
            :key="action.label"
            :variant="action.label === 'New MR' ? 'default' : 'outline'"
            size="sm"
            as-child
          >
            <RouterLink :to="action.to">
              <component :is="action.icon" />
              {{ action.label }}
            </RouterLink>
          </Button>
        </div>
      </div>

      <div v-if="error && !dashData" class="error-state" role="alert">{{ error }}</div>

      <section v-else-if="hasAnyCard" class="pending-cards" aria-label="Operational counts">
        <RouterLink
          v-if="showPendingMr"
          to="/maintenance?tab=pending-approval"
          class="pending-card pending-card--mr"
        >
          <span class="pending-card-icon"><ClipboardList aria-hidden="true" /></span>
          <span class="pending-card-body">
            <span class="pending-card-label">Pending MR</span>
            <strong class="pending-card-count">{{
              countsLoading ? '—' : (dashData?.summary.pending_maintenance_requests ?? 0)
            }}</strong>
          </span>
          <span class="pending-card-link">Review requests <ArrowRight aria-hidden="true" /></span>
        </RouterLink>

        <RouterLink
          v-if="showOpenWo"
          to="/work-orders?tab=open"
          class="pending-card pending-card--wo"
        >
          <span class="pending-card-icon"><Wrench aria-hidden="true" /></span>
          <span class="pending-card-body">
            <span class="pending-card-label">Open Work Orders</span>
            <strong class="pending-card-count">{{
              countsLoading ? '—' : (dashData?.summary.open_work_orders ?? 0)
            }}</strong>
          </span>
          <span class="pending-card-link">View work orders <ArrowRight aria-hidden="true" /></span>
        </RouterLink>

        <RouterLink
          v-if="showOverduePm"
          to="/admin/pm-rules"
          class="pending-card pending-card--pm"
        >
          <span class="pending-card-icon"><CalendarClock aria-hidden="true" /></span>
          <span class="pending-card-body">
            <span class="pending-card-label">Overdue PM</span>
            <strong class="pending-card-count">{{
              countsLoading ? '—' : (dashData?.summary.overdue_pm_assignments ?? 0)
            }}</strong>
          </span>
          <span class="pending-card-link">Manage PM rules <ArrowRight aria-hidden="true" /></span>
        </RouterLink>
      </section>

      <PendingRequirementPlaceholder :icon="Gauge">
        <template #title>Awaiting LDC requirement receipt</template>
        The dashboard is on hold pending receipt of LDC’s requirements. Once we receive them, this
        page will be built to match what LDC asks for.
      </PendingRequirementPlaceholder>
    </div>
  </AppLayout>
</template>
