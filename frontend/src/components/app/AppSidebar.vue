<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { Component } from 'vue'
import {
  Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupContent,
  SidebarGroupLabel, SidebarHeader, SidebarMenu, SidebarMenuButton,
  SidebarMenuItem, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem,
} from '@/components/ui/sidebar'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { useAuthStore } from '@/stores/auth.store'
import {
  LayoutDashboard, ClipboardList, Wrench, HardDrive, Package, Settings,
  ChevronUp,
} from '@lucide/vue'

// ── Types ─────────────────────────────────────────────────────────────────────
interface RoleFlags {
  isAdmin: boolean
  isAdminOrManager: boolean
  isTechnician: boolean
  isLogistics: boolean
  isRequester: boolean
}
interface NavLinkDef {
  kind: 'link'; label: string; to: string; icon: Component
  visibleTo: (r: RoleFlags) => boolean
}
interface NavGroupDef {
  kind: 'group'; label: string; icon: Component
  visibleTo: (r: RoleFlags) => boolean
  items: { label: string; to: string; action?: boolean; visibleTo: (r: RoleFlags) => boolean }[]
}
type NavNodeDef = NavLinkDef | NavGroupDef
interface DisplayLink { kind: 'link'; label: string; to: string; icon: Component }
interface DisplayGroup { kind: 'group'; label: string; icon: Component; items: { label: string; to: string; action?: boolean }[] }
type DisplayNode = DisplayLink | DisplayGroup

// ── Nav tree (migrated verbatim from AppNav) ─────────────────────────────────
const navTree: NavNodeDef[] = [
  { kind: 'link', label: 'Dashboard', to: '/dashboard', icon: LayoutDashboard, visibleTo: () => true },
  {
    kind: 'group', label: 'Maintenance Requests', icon: ClipboardList, visibleTo: () => true,
    items: [
      { label: 'New Request', to: '/maintenance?action=new', action: true, visibleTo: () => true },
      { label: 'Pending Approval', to: '/maintenance?tab=pending-approval', visibleTo: (r) => r.isAdminOrManager },
      { label: 'My Requests', to: '/maintenance?tab=my-requests', visibleTo: () => true },
      { label: 'All Requests', to: '/maintenance?tab=all-requests', visibleTo: (r) => r.isAdminOrManager },
    ],
  },
  {
    kind: 'group', label: 'Work Orders', icon: Wrench,
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
    items: [
      { label: 'My Work Orders', to: '/work-orders?tab=my-work-orders', visibleTo: (r) => r.isTechnician },
      { label: 'All', to: '/work-orders?tab=all', visibleTo: (r) => r.isAdminOrManager },
      { label: 'Active', to: '/work-orders?tab=active', visibleTo: (r) => r.isAdminOrManager || r.isTechnician },
      { label: 'Completed', to: '/work-orders?tab=completed', visibleTo: (r) => r.isAdminOrManager || r.isTechnician },
      { label: 'Closed', to: '/work-orders?tab=closed', visibleTo: (r) => r.isAdminOrManager || r.isTechnician },
    ],
  },
  { kind: 'link', label: 'Assets', to: '/assets', icon: HardDrive, visibleTo: (r) => r.isAdminOrManager || r.isTechnician || r.isLogistics },
  { kind: 'link', label: 'Parts', to: '/parts', icon: Package, visibleTo: (r) => r.isAdminOrManager || r.isTechnician },
  {
    kind: 'group', label: 'Settings', icon: Settings, visibleTo: (r) => r.isAdminOrManager,
    items: [
      { label: 'PM Rules', to: '/settings/pm-rules', visibleTo: (r) => r.isAdminOrManager },
      { label: 'Lists & Dropdowns', to: '/settings/lists', visibleTo: (r) => r.isAdmin },
      { label: 'Users', to: '/settings/users', visibleTo: (r) => r.isAdmin },
      { label: 'Locations', to: '/settings/locations', visibleTo: (r) => r.isAdmin },
      { label: 'System', to: '/settings/system', visibleTo: (r) => r.isAdminOrManager },
      { label: 'Activity Logs', to: '/settings/audit-logs', visibleTo: (r) => r.isAdmin },
    ],
  },
]

// ── Store + computed nodes (migrated verbatim) ────────────────────────────────
const route = useRoute()
const auth = useAuthStore()

const visibleNodes = computed<DisplayNode[]>(() => {
  const r: RoleFlags = {
    isAdmin: auth.isAdmin,
    isAdminOrManager: auth.isAdminOrManager,
    isTechnician: auth.isTechnician,
    isLogistics: auth.isLogistics,
    isRequester: auth.isRequester,
  }
  return navTree.flatMap((node): DisplayNode[] => {
    if (node.kind === 'link') {
      return node.visibleTo(r) ? [{ kind: 'link', label: node.label, to: node.to, icon: node.icon }] : []
    }
    if (!node.visibleTo(r)) return []
    const items = node.items.filter((i) => i.visibleTo(r)).map((i) => ({ label: i.label, to: i.to, action: i.action }))
    return items.length > 0 ? [{ kind: 'group', label: node.label, icon: node.icon, items }] : []
  })
})

function toLocation(to: string): string | { path: string; query: Record<string, string> } {
  if (!to.includes('?')) return to
  const [path, qs] = to.split('?') as [string, string]
  const query: Record<string, string> = {}
  new URLSearchParams(qs).forEach((v, k) => { query[k] = v })
  return { path, query }
}
function isLinkActive(to: string): boolean {
  if (!to.includes('?')) {
    if (to === '/dashboard') return route.path === '/dashboard'
    return route.path === to || route.path.startsWith(to + '/')
  }
  const [path, qs] = to.split('?') as [string, string]
  const tabParam = new URLSearchParams(qs).get('tab')
  return route.path === path && route.query['tab'] === tabParam
}
function isGroupActive(group: DisplayGroup): boolean {
  if (group.items.some((i) => isLinkActive(i.to))) return true
  if (group.label === 'Maintenance Requests' && route.path === '/maintenance') return true
  if (group.label === 'Work Orders' && route.path === '/work-orders') return true
  if (group.label === 'Settings' && route.path.startsWith('/settings')) return true
  return false
}
async function handleLogout() { await auth.logout() }
</script>

<template>
  <Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
      <div class="sidebar-brand">
        <img src="@/assets/logo.svg" alt="ATMS" class="sidebar-brand-logo" />
      </div>
    </SidebarHeader>

    <SidebarContent>
      <SidebarGroup v-for="node in visibleNodes" :key="node.kind === 'link' ? node.to : node.label">
        <SidebarGroupLabel v-if="node.kind === 'group'">{{ node.label }}</SidebarGroupLabel>
        <SidebarGroupContent>
          <SidebarMenu>
            <!-- Top-level link -->
            <SidebarMenuItem v-if="node.kind === 'link'">
              <SidebarMenuButton :is-active="isLinkActive(node.to)" :tooltip="node.label" as-child>
                <RouterLink :to="toLocation(node.to)">
                  <component :is="node.icon" />
                  <span>{{ node.label }}</span>
                </RouterLink>
              </SidebarMenuButton>
            </SidebarMenuItem>

            <!-- Group with sub-items -->
            <template v-else>
              <SidebarMenuItem>
                <SidebarMenuButton :is-active="isGroupActive(node)" :tooltip="node.label">
                  <component :is="node.icon" />
                  <span>{{ node.label }}</span>
                </SidebarMenuButton>
                <SidebarMenuSub>
                  <SidebarMenuSubItem v-for="item in node.items" :key="item.to">
                    <SidebarMenuSubButton :is-active="isLinkActive(item.to)" as-child>
                      <RouterLink :to="toLocation(item.to)">{{ item.label }}</RouterLink>
                    </SidebarMenuSubButton>
                  </SidebarMenuSubItem>
                </SidebarMenuSub>
              </SidebarMenuItem>
            </template>
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
