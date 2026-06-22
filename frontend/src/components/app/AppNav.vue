<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { Component } from 'vue'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Sheet, SheetContent, SheetTrigger } from '@/components/ui/sheet'
import { useAuthStore } from '@/stores/auth.store'
import { useUiStore } from '@/stores/ui.store'
import {
  LayoutDashboard,
  ClipboardList,
  Wrench,
  HardDrive,
  Package,
  Settings,
  Menu,
  ChevronUp,
  ChevronLeft,
  ChevronRight,
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
  kind: 'link'
  label: string
  to: string
  icon: Component
  visibleTo: (r: RoleFlags) => boolean
}

interface NavGroupDef {
  kind: 'group'
  label: string
  icon: Component
  visibleTo: (r: RoleFlags) => boolean
  items: { label: string; to: string; action?: boolean; visibleTo: (r: RoleFlags) => boolean }[]
}

type NavNodeDef = NavLinkDef | NavGroupDef

interface DisplayLink  { kind: 'link';  label: string; to: string; icon: Component }
interface DisplayGroup { kind: 'group'; label: string; icon: Component; items: { label: string; to: string; action?: boolean }[] }
type DisplayNode = DisplayLink | DisplayGroup

// ── Nav tree ──────────────────────────────────────────────────────────────────

const navTree: NavNodeDef[] = [
  {
    kind: 'link',
    label: 'Dashboard',
    to: '/dashboard',
    icon: LayoutDashboard,
    visibleTo: () => true,
  },
  {
    kind: 'group',
    label: 'Maintenance Requests',
    icon: ClipboardList,
    visibleTo: () => true,
    items: [
      {
        label: 'New Request',
        to: '/maintenance?action=new',
        action: true,
        visibleTo: () => true,
      },
      {
        label: 'Pending Approval',
        to: '/maintenance?tab=pending-approval',
        visibleTo: (r) => r.isAdminOrManager,
      },
      {
        label: 'My Requests',
        to: '/maintenance?tab=my-requests',
        visibleTo: () => true,
      },
      {
        label: 'All Requests',
        to: '/maintenance?tab=all-requests',
        visibleTo: (r) => r.isAdminOrManager,
      },
    ],
  },
  {
    kind: 'group',
    label: 'Work Orders',
    icon: Wrench,
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
    items: [
      {
        label: 'My Work Orders',
        to: '/work-orders?tab=my-work-orders',
        visibleTo: (r) => r.isTechnician,
      },
      {
        label: 'All',
        to: '/work-orders?tab=all',
        visibleTo: (r) => r.isAdminOrManager,
      },
      {
        label: 'Active',
        to: '/work-orders?tab=active',
        visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
      },
      {
        label: 'Completed',
        to: '/work-orders?tab=completed',
        visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
      },
      {
        label: 'Closed',
        to: '/work-orders?tab=closed',
        visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
      },
    ],
  },
  {
    kind: 'link',
    label: 'Assets',
    to: '/assets',
    icon: HardDrive,
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician || r.isLogistics,
  },
  {
    kind: 'link',
    label: 'Parts',
    to: '/parts',
    icon: Package,
    visibleTo: (r) => r.isAdminOrManager || r.isTechnician,
  },
  {
    kind: 'group',
    label: 'Settings',
    icon: Settings,
    visibleTo: (r) => r.isAdminOrManager,
    items: [
      {
        label: 'PM Rules',
        to: '/settings/pm-rules',
        visibleTo: (r) => r.isAdminOrManager,
      },
      {
        label: 'Lists & Dropdowns',
        to: '/settings/lists',
        visibleTo: (r) => r.isAdmin,
      },
      {
        label: 'Users',
        to: '/settings/users',
        visibleTo: (r) => r.isAdmin,
      },
      {
        label: 'Locations',
        to: '/settings/locations',
        visibleTo: (r) => r.isAdmin,
      },
      {
        label: 'System',
        to: '/settings/system',
        visibleTo: (r) => r.isAdminOrManager,
      },
      {
        label: 'Activity Logs',
        to: '/settings/audit-logs',
        visibleTo: (r) => r.isAdmin,
      },
    ],
  },
]

// ── Store references ──────────────────────────────────────────────────────────

const route = useRoute()
const auth  = useAuthStore()
const ui    = useUiStore()

// ── Visible nodes (role-filtered) ─────────────────────────────────────────────

const visibleNodes = computed<DisplayNode[]>(() => {
  const r: RoleFlags = {
    isAdmin:          auth.isAdmin,
    isAdminOrManager: auth.isAdminOrManager,
    isTechnician:     auth.isTechnician,
    isLogistics:      auth.isLogistics,
    isRequester:      auth.isRequester,
  }

  return navTree.flatMap((node): DisplayNode[] => {
    if (node.kind === 'link') {
      return node.visibleTo(r)
        ? [{ kind: 'link', label: node.label, to: node.to, icon: node.icon }]
        : []
    }
    if (!node.visibleTo(r)) return []
    const items = node.items
      .filter((i) => i.visibleTo(r))
      .map((i) => ({ label: i.label, to: i.to, action: i.action }))
    return items.length > 0
      ? [{ kind: 'group', label: node.label, icon: node.icon, items }]
      : []
  })
})

// ── Active detection ──────────────────────────────────────────────────────────

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
  if (group.label === 'Work Orders'           && route.path === '/work-orders') return true
  if (group.label === 'Settings'              && route.path.startsWith('/settings')) return true
  return false
}

// ── Mobile ────────────────────────────────────────────────────────────────────

const mobileOpen = ref(false)

async function handleLogout() {
  mobileOpen.value = false
  await auth.logout()
}
</script>

<template>
  <!-- ── Desktop sidebar ── -->
  <aside class="app-sidebar">

    <div class="sidebar-logo-area">
      <RouterLink to="/dashboard" class="sidebar-logo-link">
        <img src="@/assets/logo_w.svg" alt="ATMS" class="sidebar-logo-img" />
        <span class="sidebar-logo-mark" aria-hidden="true">AT</span>
      </RouterLink>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
      <template v-for="node in visibleNodes" :key="node.kind === 'link' ? node.to : node.label">

        <RouterLink
          v-if="node.kind === 'link'"
          :to="node.to"
          :title="ui.sidebarCollapsed ? node.label : undefined"
          :class="['sidebar-nav-item', isLinkActive(node.to) ? 'sidebar-nav-item-active' : 'sidebar-nav-item-normal']"
          active-class=""
        >
          <component :is="node.icon" class="sidebar-nav-item-icon" />
          <span class="sidebar-nav-label">{{ node.label }}</span>
        </RouterLink>

        <div v-else class="sidebar-nav-group">

          <RouterLink
            :to="toLocation(node.items[0]?.to ?? '/')"
            :title="node.label"
            :class="['sidebar-nav-item sidebar-nav-group-icon', isGroupActive(node) ? 'sidebar-nav-item-active' : 'sidebar-nav-item-normal']"
            active-class=""
          >
            <component :is="node.icon" class="sidebar-nav-item-icon" />
          </RouterLink>

          <span class="sidebar-nav-section">{{ node.label }}</span>

          <div class="sidebar-nav-group-items">
            <RouterLink
              v-for="item in node.items"
              :key="item.to"
              :to="toLocation(item.to)"
              :class="[
                item.action ? 'sidebar-nav-action' : 'sidebar-nav-subitem',
                !item.action && (isLinkActive(item.to) ? 'sidebar-nav-subitem-active' : 'sidebar-nav-subitem-normal'),
              ]"
              active-class=""
            >
              {{ item.label }}
            </RouterLink>
          </div>

        </div>

      </template>
    </nav>

    <div class="sidebar-user-area">
      <DropdownMenu :modal="false">
        <DropdownMenuTrigger as-child>
          <Button
            variant="ghost"
            class="sidebar-user-btn"
            :title="ui.sidebarCollapsed ? auth.user?.name : undefined"
            :aria-label="`User menu for ${auth.user?.name}`"
          >
            <span class="sidebar-avatar">{{ auth.userInitials }}</span>
            <span class="sidebar-user-info sidebar-nav-label">
              <span class="sidebar-user-name">{{ auth.user?.name }}</span>
              <span class="sidebar-user-role">{{ auth.user?.role?.name }}</span>
            </span>
            <ChevronUp class="sidebar-chevron icon-xs sidebar-nav-label" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent side="top" align="start" :side-offset="8" class="sidebar-dropdown-content">
          <DropdownMenuLabel class="sidebar-dropdown-header">
            <span class="sidebar-dropdown-name">{{ auth.user?.name }}</span>
            <span class="sidebar-dropdown-email">{{ auth.user?.email }}</span>
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuItem @click="auth.logout()">Sign out</DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>

  </aside>

  <!-- ── Sidebar toggle ── -->
  <Button
    variant="ghost"
    class="sidebar-toggle-btn"
    :title="ui.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
    @click="ui.toggleSidebar()"
  >
    <ChevronLeft v-if="!ui.sidebarCollapsed" class="icon-xs" />
    <ChevronRight v-else class="icon-xs" />
  </Button>

  <!-- ── Mobile top bar ── -->
  <div class="app-mobile-topbar" aria-hidden="true">
    <img src="@/assets/logo_w.svg" alt="ATMS" class="mobile-topbar-logo" />

    <Sheet v-model:open="mobileOpen">
      <SheetTrigger as-child>
        <Button variant="ghost" class="mobile-menu-btn" aria-label="Open navigation">
          <Menu class="icon-md" />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" class="sidebar-mobile-sheet">

        <div class="sidebar-logo-area">
          <RouterLink to="/dashboard" class="sidebar-logo-link" @click="mobileOpen = false">
            <img src="@/assets/logo_w.svg" alt="ATMS" class="sidebar-logo-img" />
          </RouterLink>
        </div>

        <nav class="sidebar-nav" aria-label="Mobile navigation">
          <template v-for="node in visibleNodes" :key="node.kind === 'link' ? node.to : node.label">

            <RouterLink
              v-if="node.kind === 'link'"
              :to="node.to"
              :class="['sidebar-nav-item', isLinkActive(node.to) ? 'sidebar-nav-item-active' : 'sidebar-nav-item-normal']"
              active-class=""
              @click="mobileOpen = false"
            >
              <component :is="node.icon" class="sidebar-nav-item-icon" />
              <span>{{ node.label }}</span>
            </RouterLink>

            <div v-else class="sidebar-nav-group">
              <span class="sidebar-nav-section">{{ node.label }}</span>
              <div class="sidebar-nav-group-items">
                <RouterLink
                  v-for="item in node.items"
                  :key="item.to"
                  :to="toLocation(item.to)"
                  :class="[
                    item.action ? 'sidebar-nav-action' : 'sidebar-nav-subitem',
                    !item.action && (isLinkActive(item.to) ? 'sidebar-nav-subitem-active' : 'sidebar-nav-subitem-normal'),
                  ]"
                  active-class=""
                  @click="mobileOpen = false"
                >
                  {{ item.label }}
                </RouterLink>
              </div>
            </div>

          </template>
        </nav>

        <div class="sidebar-user-area">
          <div class="sidebar-user-static">
            <span class="sidebar-avatar">{{ auth.userInitials }}</span>
            <span class="sidebar-user-info">
              <span class="sidebar-user-name">{{ auth.user?.name }}</span>
              <span class="sidebar-user-role">{{ auth.user?.role?.name }}</span>
            </span>
          </div>
          <Button
            variant="ghost"
            class="sidebar-nav-item sidebar-nav-item-normal sidebar-sign-out"
            @click="handleLogout"
          >
            Sign out
          </Button>
        </div>

      </SheetContent>
    </Sheet>
  </div>
</template>
