<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { Component } from 'vue'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent,
  SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem,
} from '@/components/ui/sidebar'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { useAuthStore } from '@/stores/auth.store'
import {
  LayoutDashboard, ClipboardList, Wrench, HardDrive, Package, Settings,
  Shield, ChevronUp,
} from '@lucide/vue'

// ── Types ─────────────────────────────────────────────────────────────────────
interface RoleFlags {
  isAdmin: boolean
  isAdminOrManager: boolean
  isTechnician: boolean
  isLogistics: boolean
  isRequester: boolean
}
interface NavItemDef {
  label: string
  icon: Component
  to: (r: RoleFlags) => string
  isActiveFor: (path: string) => boolean
  visibleTo: (r: RoleFlags) => boolean
}
interface DisplayItem {
  label: string
  icon: Component
  to: string
  isActive: boolean
}

// ── Nav tree — flat list, one entry per sidebar item ─────────────────────────
const navItems: NavItemDef[] = [
  {
    label: 'Dashboard',
    icon: LayoutDashboard,
    to: () => '/dashboard',
    isActiveFor: (p) => p === '/dashboard',
    visibleTo: () => true,
  },
  {
    label: 'Maintenance Requests',
    icon: ClipboardList,
    to: (r) => r.isAdminOrManager ? '/maintenance?tab=all-requests' : '/maintenance?tab=my-requests',
    isActiveFor: (p) => p === '/maintenance' || p.startsWith('/maintenance/'),
    visibleTo: () => true,
  },
  {
    label: 'Work Orders',
    icon: Wrench,
    to: (r) => r.isAdminOrManager ? '/work-orders?tab=all' : '/work-orders?tab=my-work-orders',
    isActiveFor: (p) => p === '/work-orders' || p.startsWith('/work-orders/'),
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
  },
  {
    label: 'Asset Management',
    icon: HardDrive,
    to: () => '/assets?tab=all-assets',
    isActiveFor: (p) => p === '/assets' || p.startsWith('/assets/'),
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician || r.isLogistics,
  },
  {
    label: 'Parts Management',
    icon: Package,
    to: () => '/parts?tab=all-parts',
    isActiveFor: (p) => p === '/parts' || p.startsWith('/parts/'),
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
  },
  {
    label: 'Admin',
    icon: Shield,
    to: () => '/settings/users',
    isActiveFor: (p) => p === '/settings/users' || p === '/settings/lists' || p.startsWith('/settings/pm-rules'),
    visibleTo: (r) => r.isAdmin,
  },
  {
    label: 'Settings',
    icon: Settings,
    to: () => '/settings/system',
    isActiveFor: (p) => p === '/settings/system' || p === '/settings/audit-logs',
    visibleTo: (r) => r.isAdmin,
  },
]

// ── Store + computed ──────────────────────────────────────────────────────────
const route = useRoute()
const auth = useAuthStore()

const visibleItems = computed<DisplayItem[]>(() => {
  const r: RoleFlags = {
    isAdmin: auth.isAdmin,
    isAdminOrManager: auth.isAdminOrManager,
    isTechnician: auth.isTechnician,
    isLogistics: auth.isLogistics,
    isRequester: auth.isRequester,
  }
  return navItems
    .filter((item) => item.visibleTo(r))
    .map((item) => ({
      label: item.label,
      icon: item.icon,
      to: item.to(r),
      isActive: item.isActiveFor(route.path),
    }))
})

function toLocation(to: string): string | { path: string; query: Record<string, string> } {
  if (!to.includes('?')) return to
  const [path, qs] = to.split('?') as [string, string]
  const query: Record<string, string> = {}
  new URLSearchParams(qs).forEach((v, k) => { query[k] = v })
  return { path, query }
}

async function handleLogout() { await auth.logout() }
</script>

<template>
  <Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
      <div class="sidebar-brand">
        <img src="@/assets/logo.svg" alt="ATMS" class="sidebar-brand-logo" />
        <span class="sidebar-brand-mark" aria-hidden="true">ATMS</span>
      </div>
    </SidebarHeader>

    <SidebarContent>
      <SidebarGroup>
        <SidebarGroupContent>
          <SidebarMenu>
            <SidebarMenuItem v-for="item in visibleItems" :key="item.label">
              <SidebarMenuButton :is-active="item.isActive" :tooltip="item.label" as-child>
                <RouterLink :to="toLocation(item.to)">
                  <component :is="item.icon" />
                  <span>{{ item.label }}</span>
                </RouterLink>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroupContent>
      </SidebarGroup>
    </SidebarContent>

    <SidebarFooter>
      <SidebarMenu>
        <SidebarMenuItem>
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <SidebarMenuButton size="lg" :aria-label="`User menu for ${auth.user?.name ?? 'user'}`">
                <Avatar><AvatarFallback>{{ auth.userInitials }}</AvatarFallback></Avatar>
                <div class="sidebar-user-info">
                  <span class="sidebar-user-name">{{ auth.user?.name }}</span>
                  <span class="sidebar-user-role">{{ auth.user?.role?.name }}</span>
                </div>
                <ChevronUp class="sidebar-user-chevron" />
              </SidebarMenuButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="end">
              <DropdownMenuLabel>{{ auth.user?.email }}</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem @click="handleLogout">Sign out</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </SidebarMenuItem>
      </SidebarMenu>
    </SidebarFooter>
  </Sidebar>
</template>
