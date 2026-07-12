<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ArrowLeft } from '@lucide/vue'
import AppLayout from '@/components/app/AppLayout.vue'

/**
 * Shared shell for a single report page: back-to-catalogue link, page header,
 * and ordered slots for the filter bar, summary strip, and results content.
 * Presentation only — each report owns its data via a `useXxxReport()` composable.
 */
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <AppLayout>
    <div class="page-section">
      <RouterLink to="/reports" class="report-back">
        <ArrowLeft class="report-back-icon" aria-hidden="true" />
        Back to Reports
      </RouterLink>

      <div class="page-header">
        <div class="page-heading">
          <h1 class="page-title">{{ title }}</h1>
          <p v-if="subtitle" class="page-subtitle">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="page-actions">
          <slot name="actions" />
        </div>
      </div>

      <slot name="filters" />
      <slot name="summary" />
      <slot />
    </div>
  </AppLayout>
</template>
