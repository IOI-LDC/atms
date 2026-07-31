<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { Button } from '@/components/ui/button'
import { BookOpen } from '@lucide/vue'
import AppSidebar from './AppSidebar.vue'
import AppUserMenu from './AppUserMenu.vue'
</script>

<template>
  <SidebarProvider>
    <AppSidebar />
    <SidebarInset>
      <header class="app-shell-header">
        <div class="app-shell-header-left">
          <SidebarTrigger />
        </div>
        <div class="app-shell-header-right">
          <Button
            variant="ghost"
            size="icon"
            as-child
            class="app-topbar-link"
            aria-label="User Manual"
            title="User Manual"
          >
            <RouterLink to="/user-manual">
              <BookOpen />
            </RouterLink>
          </Button>
          <AppUserMenu />
        </div>
      </header>
      <main class="app-shell-main">
        <slot />
      </main>
    </SidebarInset>
  </SidebarProvider>
</template>
