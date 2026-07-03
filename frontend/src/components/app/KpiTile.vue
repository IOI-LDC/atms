<script setup lang="ts">
import type { Component } from 'vue'

/**
 * Compact dashboard stat tile: tinted icon chip + title + value + optional
 * subtitle. The accent colour of the chip is set by the parent group's
 * `.kpi-accent-*` class, so tiles stay presentation-only and reusable.
 */
defineProps<{
  icon: Component
  title: string
  value: string | number
  subtitle?: string
}>()
</script>

<template>
  <div class="kpi-tile">
    <span class="kpi-tile-icon"><component :is="icon" /></span>
    <span class="kpi-tile-body">
      <span class="kpi-tile-title">{{ title }}</span>
      <span class="kpi-tile-value">{{ value }}</span>
      <span v-if="subtitle" class="kpi-tile-sub">{{ subtitle }}</span>
    </span>
  </div>
</template>
