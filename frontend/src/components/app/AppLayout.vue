<script setup lang="ts">
import AppNav from './AppNav.vue'
import { useUiStore } from '@/stores/ui.store'

const ui = useUiStore()

const today = new Date().toISOString().slice(0, 10)
</script>

<template>
  <div :class="['app-layout', { 'sidebar-collapsed': ui.sidebarCollapsed }]">
    <AppNav />
    <main class="app-main">
      <header class="app-bar">
        <span class="app-bar-title">Asset Tracking and Maintenance System</span>
        <span class="app-bar-date">{{ today }}</span>
      </header>
      <div class="app-content">
        <slot />
      </div>
    </main>
  </div>
</template>
