<script setup lang="ts">
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import AppSidebar from './AppSidebar.vue'
</script>

<template>
  <SidebarProvider>
    <AppSidebar />
    <SidebarInset>
      <header class="app-shell-header">
        <SidebarTrigger />
      </header>
      <main class="app-shell-main">
        <slot />
      </main>
    </SidebarInset>
  </SidebarProvider>
</template>
