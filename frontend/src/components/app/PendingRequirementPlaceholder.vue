<script setup lang="ts">
import type { Component } from 'vue'
import { Hourglass } from '@lucide/vue'

defineProps<{
  icon?: Component
  badge?: string
}>()
</script>

<template>
  <section class="pending-placeholder" aria-live="polite">
    <div class="pending-placeholder-panel">
      <span class="pending-placeholder-badge">{{ badge ?? 'Pending LDC requirements' }}</span>
      <component :is="icon ?? Hourglass" class="pending-placeholder-icon" aria-hidden="true" />
      <h2 class="pending-placeholder-title">
        <slot name="title">Awaiting LDC requirement receipt</slot>
      </h2>
      <p class="pending-placeholder-text">
        <slot />
      </p>
    </div>
  </section>
</template>
