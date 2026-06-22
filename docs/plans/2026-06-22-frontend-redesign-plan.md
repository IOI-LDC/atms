# Frontend Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the bespoke dark-sidebar / floating-toggle app shell with the standard shadcn Inset sidebar pattern, rewrite the central stylesheet's token block and class vocabulary to match shadcn defaults, and mechanically migrate all 27 views — without touching composables, stores, API layer, or auth pages.

**Architecture:** Single-source-of-truth CSS preserved (semantic classes only in feature files), but the classes themselves are rewritten to compose shadcn primitives (`Sidebar*`, `Card`, `Button`, …). App shell moves from custom `AppNav` to shadcn `SidebarProvider` + `SidebarInset`. Light theme only; dark block deleted.

**Tech Stack:** Vue 3.5 `<script setup>` + TypeScript, Vite, Tailwind v4 (inside `components/ui/` only), shadcn-vue + Reka UI, Pinia, `@ioi-dev/vue-table`.

**Design doc:** `docs/plans/2026-06-22-frontend-redesign-design.md` — read it first.

**Verification method:** This project has **no test runner**. Each task's gate is `npm run type-check` plus targeted `grep` checks. The final task runs the full §10 gate from the design doc.

**Working directory for all commands:** `frontend/` (i.e. `cd /Users/rawandhawez/Desktop/LDC/atms/frontend` or use `workdir`).

---

## Migration mapping table (referenced by Phase D tasks)

| Old class / pattern                  | New                                                |
|--------------------------------------|----------------------------------------------------|
| `.app-layout`                        | `.app-shell` (composition wrapper around `SidebarProvider`) |
| `.app-main`, `.app-content`          | handled by `SidebarInset` primitive + `.page`      |
| `.app-bar`, `.app-bar-title`, `.app-bar-date` | **DELETE** — pages render their own `.page-header` |
| `.data-card`, `.data-card-header/title/description/content/actions` | `<Card>` / `<CardHeader>` / `<CardTitle>` / `<CardDescription>` / `<CardContent>` / `<CardAction>` primitives |
| `.page-section`                      | `.page`                                            |
| `.page-heading`, `.page-subtitle`    | `.page-header` (wrap) + `.page-description`        |
| `.card-grid`                         | `.kpi-grid` (if metrics) or a generic grid arrangement class |
| `.status-badge`, `.status-*`         | kept (token-driven, see Task 6)                    |
| `.sidebar-toggle-btn`, `.app-mobile-topbar`, `.mobile-topbar-logo` | **DELETE** — `SidebarTrigger` replaces them |
| custom `.sidebar-nav-*`, `.sidebar-user-*`, `.sidebar-logo-*` | **DELETE** — `SidebarMenu*` / `SidebarFooter` primitives own this |
| raw `<button>` / `<input>`            | `<Button>` / `<Input>` / `<FileInput>` primitives  |

**Rule of thumb:** if a primitive exists for it, use the primitive. Use a class only for arrangement + status semantics.

---

## Phase A — Token foundation

### Task 1: Replace the token block; remove dark mode

**Files:**
- Modify: `frontend/src/style.css` lines 1–128 (the `:root` + `@theme inline` + `@layer base` block)

**Step 1: Replace the top of `style.css`** (everything from line 1 through the closing `}` of `@layer base`, currently line 128) with the block below.

Keep the existing `@import` lines at the very top, but **drop `@import url('…Geist…')`** only if you also remove Geist from `--font-sans` (the user palette uses Lato/Merriweather/Roboto Mono — see below). Keep `tw-animate-css`, `shadcn-vue/tailwind.css`, `vue-sonner/style.css` imports.

```css
@import "tailwindcss";
@import "tw-animate-css";
@import "shadcn-vue/tailwind.css";
@import "vue-sonner/style.css";

/* NOTE: `@custom-variant dark` and the `.dark { … }` block are intentionally
   absent — ATMS is light-theme only. Do not re-add them. */

:root {
  --background: oklch(0.9940 0 0);
  --foreground: oklch(0.4148 0.1297 262.3056);
  --card: oklch(0.9940 0 0);
  --card-foreground: oklch(0.4148 0.1297 262.3056);
  --popover: oklch(0.9911 0 0);
  --popover-foreground: oklch(0 0 0);
  --primary: oklch(0.4148 0.1297 262.3056);
  --primary-foreground: oklch(1.0000 0 0);
  --secondary: oklch(0.9540 0.0063 255.4755);
  --secondary-foreground: oklch(0.4148 0.1297 262.3056);
  --muted: oklch(0.9702 0 0);
  --muted-foreground: oklch(0.4386 0 0);
  --accent: oklch(0.9393 0.0288 266.3680);
  --accent-foreground: oklch(0.4148 0.1297 262.3056);
  --destructive: oklch(0.6290 0.1902 23.0704);
  --destructive-foreground: oklch(1.0000 0 0);
  --border: oklch(0.9300 0.0094 286.2156);
  --input: oklch(0.9401 0 0);
  --ring: oklch(0.8181 0.0042 286.3076);
  --chart-1: oklch(0.7459 0.1483 156.4499);
  --chart-2: oklch(0.5393 0.2713 286.7462);
  --chart-3: oklch(0.7336 0.1758 50.5517);
  --chart-4: oklch(0.5828 0.1809 259.7276);
  --chart-5: oklch(0.5590 0 0);

  /* Status tokens — reused from previous palette, harmonised with status classes */
  --success:              oklch(0.530 0.145 142);
  --success-foreground:   oklch(0.985 0 0);
  --warning:              oklch(0.700 0.155 75);
  --warning-foreground:   oklch(0.145 0 0);
  --info:                 oklch(0.580 0.138 232);
  --info-foreground:      oklch(0.985 0 0);

  /* Sidebar — light by design */
  --sidebar: oklch(0.9940 0 0);
  --sidebar-foreground: oklch(0.4148 0.1297 262.3056);
  --sidebar-primary: oklch(0.4148 0.1297 262.3056);
  --sidebar-primary-foreground: oklch(1.0000 0 0);
  --sidebar-accent: oklch(0.9693 0.0144 264.4994);
  --sidebar-accent-foreground: oklch(0.4148 0.1297 262.3056);
  --sidebar-border: oklch(0.9300 0.0094 286.2156);
  --sidebar-ring: oklch(0.8181 0.0042 286.3076);

  /* Typography */
  --font-sans: Lato, ui-sans-serif, sans-serif, system-ui;
  --font-serif: Merriweather, ui-serif, serif;
  --font-mono: Roboto Mono, ui-monospace, monospace;

  /* Radius / spacing / shadows (from user palette) */
  --radius: 0.4rem;
  --spacing: 0.23rem;
  --shadow-2xs: 0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.08);
  --shadow-xs:  0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.08);
  --shadow-sm:  0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.16), 0px 1px 2px -1px hsl(211.6316 106.8326% 78.5620% / 0.16);
  --shadow:     0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.16), 0px 1px 2px -1px hsl(211.6316 106.8326% 78.5620% / 0.16);
  --shadow-md:  0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.16), 0px 2px 4px -1px hsl(211.6316 106.8326% 78.5620% / 0.16);
  --shadow-lg:  0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.16), 0px 4px 6px -1px hsl(211.6316 106.8326% 78.5620% / 0.16);
  --shadow-xl:  0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.16), 0px 8px 10px -1px hsl(211.6316 106.8326% 78.5620% / 0.16);
  --shadow-2xl: 0px 2px 3px 0px hsl(211.6316 106.8326% 78.5620% / 0.40);
}

@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --color-card-foreground: var(--card-foreground);
  --color-popover: var(--popover);
  --color-popover-foreground: var(--popover-foreground);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);
  --color-secondary-foreground: var(--secondary-foreground);
  --color-muted: var(--muted);
  --color-muted-foreground: var(--muted-foreground);
  --color-accent: var(--accent);
  --color-accent-foreground: var(--accent-foreground);
  --color-destructive: var(--destructive);
  --color-destructive-foreground: var(--destructive-foreground);
  --color-success: var(--success);
  --color-success-foreground: var(--success-foreground);
  --color-warning: var(--warning);
  --color-warning-foreground: var(--warning-foreground);
  --color-info: var(--info);
  --color-info-foreground: var(--info-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);
  --color-chart-1: var(--chart-1);
  --color-chart-2: var(--chart-2);
  --color-chart-3: var(--chart-3);
  --color-chart-4: var(--chart-4);
  --color-chart-5: var(--chart-5);
  --color-sidebar: var(--sidebar);
  --color-sidebar-foreground: var(--sidebar-foreground);
  --color-sidebar-primary: var(--sidebar-primary);
  --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
  --color-sidebar-accent: var(--sidebar-accent);
  --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
  --color-sidebar-border: var(--sidebar-border);
  --color-sidebar-ring: var(--sidebar-ring);
  --font-sans: var(--font-sans);
  --font-mono: var(--font-mono);
  --font-serif: var(--font-serif);
  --radius-sm: calc(var(--radius) - 4px);
  --radius-md: calc(var(--radius) - 2px);
  --radius-lg: var(--radius);
  --radius-xl: calc(var(--radius) + 4px);
  --shadow-2xs: var(--shadow-2xs);
  --shadow-xs: var(--shadow-xs);
  --shadow-sm: var(--shadow-sm);
  --shadow: var(--shadow);
  --shadow-md: var(--shadow-md);
  --shadow-lg: var(--shadow-lg);
  --shadow-xl: var(--shadow-xl);
  --shadow-2xl: var(--shadow-2xl);
}

@layer base {
  * {
    @apply border-border outline-ring/50;
  }
  body {
    @apply bg-background text-foreground;
    font-family: var(--font-sans);
  }
}
```

**Step 2: Verify**
```bash
npm run type-check
grep -nE "@custom-variant dark|^\.dark\s*\{" src/style.css   # expect: no matches
```
Expected: type-check passes; both greps return nothing.

**Step 3: Commit**
```bash
git add frontend/src/style.css
git commit -m "feat(frontend): adopt shadcn-default light token palette; drop dark mode"
```

---

## Phase B — New app shell

### Task 2: Create `AppSidebar.vue` (shadcn Sidebar, full nav-logic migration)

**Files:**
- Create: `frontend/src/components/app/AppSidebar.vue`

**Step 1: Create the file** with the content below. This migrates `navTree`, `visibleNodes`, `isLinkActive`, `isGroupActive`, `toLocation`, `handleLogout` from `AppNav.vue` verbatim onto shadcn `Sidebar*` primitives.

```vue
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
              <SidebarMenuButton size="lg" :aria-label="`User menu for ${auth.user?.name}`">
                <Avatar><AvatarFallback>{{ auth.userInitials }}</AvatarFallback></Avatar>
                <div class="sidebar-user-info">
                  <span class="sidebar-user-name">{{ auth.user?.name }}</span>
                  <span class="sidebar-user-role">{{ auth.user?.role?.name }}</span>
                </div>
                <ChevronUp class="ml-auto" />
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
```

**Notes for the implementer:**
- Verify `SidebarMenuButton` accepts `is-active` / `tooltip` / `size` / `as-child` props by reading `components/ui/sidebar/SidebarMenuButton.vue`. If prop names differ (e.g. `isActive`), adjust accordingly. The active state must render via shadcn's `data-active` styling, not a custom class.
- `Avatar` / `AvatarFallback` exist under `components/ui/avatar/`.
- Logo asset is `logo.svg` (dark mark on light sidebar) — **not** `logo_w.svg` (which was for the dark sidebar).

**Step 2: Verify**
```bash
npm run type-check
```
Expected: passes. If `SidebarMenuButton` prop names mismatch, fix and re-run.

**Step 3: Commit**
```bash
git add frontend/src/components/app/AppSidebar.vue
git commit -m "feat(frontend): add shadcn Sidebar-based AppSidebar with migrated nav logic"
```

---

### Task 3: Rewrite `AppLayout.vue` (SidebarProvider + SidebarInset)

**Files:**
- Modify: `frontend/src/components/app/AppLayout.vue` (full rewrite, currently 23 lines)

**Step 1: Replace the entire file** with:

```vue
<script setup lang="ts">
import { SidebarInset, SidebarTrigger } from '@/components/ui/sidebar'
import { Separator } from '@/components/ui/separator'
import AppSidebar from './AppSidebar.vue'
</script>

<template>
  <div class="app-shell">
    <AppSidebar />
    <SidebarInset>
      <header class="app-shell-header">
        <SidebarTrigger />
        <Separator orientation="vertical" />
        <!-- Pages own their title via .page-header; this bar is just the trigger on mobile -->
      </header>
      <main class="page">
        <slot />
      </main>
    </SidebarInset>
  </div>
</template>
```

**Notes:**
- The `.app-bar` title + date strip is intentionally **gone**. Each page renders its own `.page-header`.
- The slim top bar exists so the `SidebarTrigger` (hamburger) is reachable on mobile; shadcn hides the desktop sidebar rail and shows this trigger as a Sheet opener on small screens.
- `Separator` primitive exists under `components/ui/separator/`.

**Step 2: Verify**
```bash
npm run type-check
grep -nE "app-bar|today|app-main|app-content" frontend/src/components/app/AppLayout.vue   # expect: no matches
```

**Step 3: Commit**
```bash
git add frontend/src/components/app/AppLayout.vue
git commit -m "feat(frontend): rewrite AppLayout around SidebarProvider + SidebarInset"
```

---

### Task 4: Delete `AppNav.vue`; remove invented shell chrome from stylesheet

**Files:**
- Delete: `frontend/src/components/app/AppNav.vue`
- Modify: `frontend/src/style.css` — remove the obsolete sidebar/app-bar/toggle blocks and add minimal shell composition classes.

**Step 1: Confirm nothing else imports `AppNav`**
```bash
grep -rn "AppNav" frontend/src --include="*.vue" --include="*.ts"
```
Expected: no matches (AppLayout was the only consumer and it now uses AppSidebar).

**Step 2: Delete the file**
```bash
git rm frontend/src/components/app/AppNav.vue
```

**Step 3: Remove obsolete CSS blocks** from `style.css`. Delete every rule whose selector starts with: `.app-layout`, `.app-sidebar`, `.app-main`, `.app-content`, `.app-bar`, `.app-bar-title`, `.app-bar-date`, `.app-mobile-topbar`, `.mobile-topbar-logo`, `.sidebar-toggle-btn` (incl. `:hover` and collapsed overrides), `.sidebar-logo-area`, `.sidebar-logo-link`, `.sidebar-logo-img`, `.sidebar-logo-mark`, `.sidebar-nav`, `.sidebar-nav-section`, `.sidebar-nav-group`, `.sidebar-nav-group-icon`, `.sidebar-nav-group-items`, `.sidebar-nav-subitem`, `.sidebar-nav-subitem-normal`, `.sidebar-nav-subitem-active`, `.sidebar-nav-action`, `.sidebar-nav-item`, `.sidebar-nav-item-icon`, `.sidebar-nav-label`, `.sidebar-user-area`, `.sidebar-user-btn`, `.sidebar-avatar`, `.sidebar-user-info`, `.sidebar-user-name`, `.sidebar-user-role`, `.sidebar-chevron`, `.sidebar-dropdown-*`, `.sidebar-mobile-sheet`, and the `.sidebar-collapsed .…` overrides for those.

**Do NOT delete:** `.atms-auth-*` (auth pages — frozen), `.status-*` / `.priority-*` (Task 6), `.kpi-*`, `.page-*` (rewritten in Task 5), `.filter-*`, `.table-*`, `.pagination-bar`, `.loading-state` / `.empty-state` / `.error-state` / `.permission-state` / `.read-only-state` / `.skeleton-grid`, `.icon-xs/-sm/-md`.

**Step 4: Add new shell composition classes** (append near the top of the post-token section):

```css
/* ── App shell (composes shadcn Sidebar primitives) ── */
.app-shell {
  display: block;
}
.app-shell-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  height: var(--nav-height, 3.5rem);
  padding: 0 1rem;
  border-bottom: 1px solid var(--border);
  background-color: var(--background);
}
.sidebar-brand {
  display: flex;
  align-items: center;
  padding: 0.5rem 0.25rem;
}
.sidebar-brand-logo {
  height: 1.5rem;
  width: auto;
}
.sidebar-user-info {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  text-align: left;
  line-height: 1.2;
}
.sidebar-user-name { font-weight: 500; font-size: 0.875rem; }
.sidebar-user-role { font-size: 0.75rem; color: var(--muted-foreground); }
```

**Step 5: Verify**
```bash
npm run type-check
grep -rnE "AppNav|sidebar-toggle-btn|app-bar\b|app-mobile-topbar|mobile-topbar-logo" frontend/src   # expect: no matches
```

**Step 6: Commit**
```bash
git add -A frontend/src
git commit -m "feat(frontend): remove bespoke AppNav and invented shell chrome"
```

---

## Phase C — Vocabulary rewrite

### Task 5: Rewrite page/layout classes

**Files:**
- Modify: `frontend/src/style.css` — the page-section / page-header / page-heading / page-title / page-subtitle / page-actions / page-content block.

**Step 1:** Replace that block with shadcn-aligned semantics (rename `.page-section` → `.page`, `.page-subtitle` → `.page-description`, drop `.page-heading`/`.page-content` wrappers in favor of `.page-header` + flow):

```css
/* ── Page layout ── */
.page {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}
.page-header {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.page-title {
  font-size: 1.5rem;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--foreground);
}
.page-description {
  font-size: 0.875rem;
  color: var(--muted-foreground);
}
.page-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
```

**Step 2: Verify** `npm run type-check`. (CSS-only change; type-check should still pass. Visual check happens in Phase E.)

**Step 3: Commit**
```bash
git add frontend/src/style.css
git commit -m "feat(frontend): rewrite page layout classes to shadcn semantics"
```

---

### Task 6: Status badge system cleanup

**Files:**
- Modify: `frontend/src/style.css` — status/priority section.

**Step 1:** Delete the older duplicate status set (`.status-pending`, `.status-converted`, `.status-rejected`, `.status-cancelled`, `.status-open`, `.status-in-progress`, `.status-completed`, `.status-closed`, `.priority-*` around lines 739–754). Keep the refined set (~1346–1367) that uses translucent token-derived backgrounds.

**Step 2:** Ensure the refined set derives colour from the status tokens (not hardcoded). For each status class, replace its hardcoded `oklch(...)` with the matching token, e.g.:
- `.status-pending-review` → `--warning`
- `.status-converted`, `.status-completed` (success family) → `--success`
- `.status-open` → `--info`
- `.status-rejected`, `.status-failed` → `--destructive`
- `.status-cancelled`, `.status-closed` → `--muted` / `--muted-foreground`
- `.priority-critical` → destructive, `.priority-high` → warning, `.priority-medium` → info, `.priority-low` → muted

Keep the translucent-background technique (e.g. `oklch(from var(--success) l c h / 0.12)` or a fixed alpha) so badges read as soft pills, not solid blocks.

**Step 3:** Confirm `.status-badge` base rule exists and is token-driven.

**Step 4: Verify**
```bash
npm run type-check
grep -cE "\.status-pending\b|\.status-converted\b" frontend/src/style.css   # expect: 1 each (only the refined set), not 2
```

**Step 5: Commit**
```bash
git add frontend/src/style.css
git commit -m "feat(frontend): dedupe status classes; drive badge colours from tokens"
```

---

### Task 7: Freeze auth styles; sanity-check remaining vocabulary

**Files:**
- Read-only verify: `frontend/src/style.css`.

**Step 1:** Confirm `.atms-auth-*` block is untouched:
```bash
grep -cE "^\.atms-auth-" frontend/src/style.css    # expect: matches the count from before Phase A
```

**Step 2:** Confirm KPI / filter / table / state classes still present and reference tokens (no hardcoded values):
```bash
grep -nE "\.kpi-|\.filter-|\.dense-table|\.table-container|\.empty-state|\.error-state|\.skeleton-grid|\.icon-(xs|sm|md)" frontend/src/style.css | head
grep -nE "#[0-9a-fA-F]{3,8}\b" frontend/src/style.css   # hex is allowed IN style.css (it's the token source) — this is informational
```
Note: hardcoded values are **allowed** in `style.css` itself (it *defines* tokens). This check is only to confirm feature files stay clean (Phase E).

**Step 3:** No commit needed if nothing changed. If you tightened any class to use a token, commit:
```bash
git add frontend/src/style.css && git commit -m "chore(frontend): align remaining vocabulary classes with tokens"
```

---

## Phase D — View migration (mechanical)

For every task in this phase, the loop is the same per file:
1. Open the view.
2. Apply the **migration mapping table** at the top of this plan:
   - `<div class="data-card …">…` → `<Card>…</Card>` (and the `data-card-*` children → `CardHeader/Title/Description/Content/Action`).
   - `<div class="page-section">` → `<div class="page">`; `.page-heading` → `.page-header`; `.page-subtitle` → `.page-description`.
   - `.card-grid` of metrics → `.kpi-grid` + `.kpi-card`.
   - Any raw `<button>`/`<input>` left over → `<Button>`/`<Input>`/`<FileInput>`.
   - `.app-bar` / `.app-main` / `.app-content` references inside views → remove (the shell owns these now).
3. Run `npm run type-check`.
4. `grep -nE "data-card|page-section|page-heading|page-subtitle|card-grid|app-bar\b|app-main|app-content" <file>` → expect no matches.
5. Commit that file (or the domain batch).

If a view already complies (e.g. uses primitives + new classes), skip it but note it.

### Task 8: Migrate Dashboard + work-orders views
**Files:** `views/DashboardView.vue`, `views/work-orders/WorkOrdersView.vue`, `views/work-orders/WorkOrdersListView.vue`, `views/work-orders/WorkOrderDetailView.vue`, `views/work-orders/MaintenanceRequestDetailView.vue`.

`WorkOrdersView.vue` is the largest (post-audit ~548 lines). Take care to preserve the confirm-create `Dialog`, the `FileInput` primitive usage, and all composable wiring — only swap classes/primitives, never logic.

Commit: `feat(frontend): migrate dashboard + work-orders views to new shell vocabulary`

### Task 9: Migrate admin views
**Files:** `views/admin/` — `AuditLogsView`, `CompanySettingsView`, `EmployeesView`, `ErpSyncView`, `ListsView`, `LocationsView`, `MasterDataView`, `SystemSettingsView`, `UsersView`.

Commit: `feat(frontend): migrate admin views to new shell vocabulary`

### Task 10: Migrate assets / parts / pm-rules / errors views
**Files:** `views/assets/` (`AssetsView`, `AssetDetailView`), `views/parts/` (`PartsView`, `PartDetailView`), `views/pm-rules/` (`PmRulesView`, `PmRuleDetailView`), `views/errors/` (`ForbiddenView`, `NotFoundView`).

**Do NOT touch** `views/auth/*` — they are standalone and frozen.

Commit: `feat(frontend): migrate remaining views to new shell vocabulary`

---

## Phase E — Final verification gate

### Task 11: Run the full §10 gate

**Step 1: Type-check**
```bash
npm run type-check
```
Expected: clean.

**Step 2: Feature-file purity greps** (all must return **no matches**):
```bash
# raw interactive elements in feature code
grep -rnE "<(button|input|select|textarea|dialog)\b" frontend/src --include="*.vue" | grep -v "components/ui/"
# inline styles
grep -rnE 'style="' frontend/src --include="*.vue" | grep -v "components/ui/"
# Tailwind utility classes in feature files
grep -rnE 'class="[^"]*\b(flex|grid|gap-[0-9]|p-[0-9]|px-|py-|mt-|mb-|text-(sm|md|lg|xs)|bg-white|bg-black|rounded|border-[a-z]|w-[0-9]|h-[0-9]|items-|justify-|space-[xy])' frontend/src --include="*.vue" | grep -v "components/ui/" | grep -vE 'class="(kpi-grid|dense-table)'
# dark mode residue
grep -rnE "@custom-variant dark|^\.dark\s*\{|prefers-color-scheme:\s*dark" frontend/src
# old vocabulary leftovers in feature files
grep -rnE "data-card|page-section|page-heading|page-subtitle|app-bar\b|app-main|app-content|sidebar-toggle-btn|sidebar-nav-|app-mobile-topbar" frontend/src --include="*.vue" --include="*.ts"
# hardcoded values in FEATURE files (style.css is allowed to define them)
grep -rnE "#[0-9a-fA-F]{3,8}\b|oklch\(" frontend/src --include="*.vue" | grep -v "components/ui/"
```

**Step 3: Behavioural smoke check (manual, document outcomes):**
- Sidebar renders **light** (`--sidebar` near-white). Complaint #1 resolved.
- Collapse uses `SidebarTrigger` in the footer; **no floating circle** on the sidebar edge.
- **No title/date `.app-bar`** on any page.
- Mobile width: hamburger in the top bar opens the sidebar as a left Sheet.
- Every status badge shows **text + colour** (toggle through a few statuses).
- Auth pages (`/login`, `/forgot-password`, `/activate`, `/reset-password`) render unchanged.

**Step 4: Commit a verification note**
```bash
git add -A
git commit -m "chore(frontend): verify redesign against §10 gate (type-check + purity greps pass)"
```

If any grep in Step 2 returns matches, fix before committing; do not declare done with outstanding matches.

---

## Notes for the executor

- **No test runner** — `npm run type-check` is the primary automated gate. Run it after every task.
- **Commit frequently** — the plan specifies a commit per task. Keep them atomic so review bisects cleanly.
- **Never touch** `composables/*`, `stores/*`, `lib/*`, `router/*`, `types/*`, `components/ui/*`, or `views/auth/*`. If a task seems to require editing one of those, stop and reconsider — the design isolates the shell from them.
- **Read each `components/ui/sidebar/*.vue` before relying on a prop** (`is-active`, `tooltip`, `as-child`, `size`). shadcn-vue prop names occasionally differ from React shadcn; verify against the installed source.
- If `SidebarMenuButton` does not support `tooltip`/`is-active` as written, adjust `AppSidebar.vue` to the actual API — the *behaviour* (active highlighting, icon-rail tooltips when collapsed) is the requirement, not the exact prop spelling.
