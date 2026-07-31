<script setup lang="ts">
/**
 * Summary strip for a report page: a row of labelled stat tiles built from the
 * endpoint's `summary` block. Values are pre-formatted by the caller (em dash for
 * "no basis", never a bare 0). Presentation only.
 */
defineProps<{
  stats: { label: string; value: string | number; hint?: string }[]
}>()
</script>

<template>
  <div class="report-summary">
    <div v-for="stat in stats" :key="stat.label" class="report-summary-stat">
      <span class="report-summary-label">{{ stat.label }}</span>
      <span class="report-summary-value">{{ stat.value }}</span>
      <span v-if="stat.hint" class="report-summary-hint">{{ stat.hint }}</span>
    </div>
  </div>
</template>
