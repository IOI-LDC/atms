<script setup lang="ts">
import { ChartColumnBig } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'
import PendingRequirementPlaceholder from '@/components/app/PendingRequirementPlaceholder.vue'
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">Reports</h1>
          <p class="page-subtitle">Awaiting LDC’s requirements for reporting.</p>
        </div>
      </div>

      <PendingRequirementPlaceholder :icon="ChartColumnBig">
        <template #title>Awaiting LDC requirement receipt</template>
        Reporting is on hold pending receipt of LDC’s requirements. Once we receive them, the
        reports suite will be built to match what LDC asks for.
      </PendingRequirementPlaceholder>
    </div>
  </AppLayout>
</template>
