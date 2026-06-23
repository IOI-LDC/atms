# Frontend Redesign — shadcn-faithful, single-source-of-truth

**Date:** 2026-06-22
**Status:** Approved (pending implementation plan)
**Scope:** `frontend/src/**`

## 1. Goal

Redesign the ATMS frontend to match the clean, restrained default shadcn aesthetic,
fixing two concrete complaints:

1. The sidebar is too dark.
2. The app shell is "strange" (floating circular collapse button, redundant title/date bar).

Root cause identified during brainstorming: the previous `ATMS_UI_RULES.md` mandated
bespoke semantic CSS classes for everything, which forced invented chrome
(`.sidebar-toggle-btn`, `.app-bar`) instead of using shadcn's standard primitives.
The rule (one central stylesheet) was sound; the *implementation* of that rule was not.

## 2. Contract (the locked decisions)

- **One central stylesheet, reused.** Feature files use semantic classes for arrangement
  and status semantics. No Tailwind utilities in feature files. Consistency preserved.
- **shadcn primitives in feature files.** `Button`, `Card`, `Input`, `Label`, `Select`,
  `Dialog`, `Sheet`, `Table`, `Tabs`, `Badge`, `Tooltip`, `DropdownMenu`, `Sidebar*`.
  These carry Tailwind utilities *inside themselves* (allowed in `components/ui/`).
- **Central semantic classes only for what no primitive covers:** page layout, grid
  composition, status badges, filter bars, KPI grids, empty/error states.
- **Light theme only.** No `.dark`, no `@custom-variant dark`, no dark-mode behavior.
- **Design tokens only.** No hardcoded hex/oklch/px in feature code.
- **Composable-first, accessibility, confirm-before-mutate** — preserved as good practice.

`ATMS_UI_RULES.md` itself is **out of scope** for this redesign and will be relaxed/rewritten
in separate later work. The contract above supersedes it for this effort.

## 3. App shell — Inset sidebar (shadcn Sidebar-7 pattern)

Built on the already-installed `components/ui/sidebar/` primitive (verified complete:
`SidebarProvider`, `Sidebar`, `SidebarInset`, `SidebarTrigger`, `variant="inset"`,
`collapsible="icon"` all present).

```
SidebarProvider
├─ AppSidebar                         (variant="inset", collapsible="icon")
│   ├─ SidebarHeader  → logo
│   ├─ SidebarContent → SidebarMenu / SidebarMenuItem / SidebarMenuButton
│   │                    + SidebarMenuSub / SidebarMenuSubButton for grouped items
│   └─ SidebarFooter  → user DropdownMenu  +  SidebarTrigger (collapse)
└─ SidebarInset
    └─ mobile: slim top bar with SidebarTrigger (opens sidebar as left Sheet)
    └─ <slot />                       ← page renders its own .page-header
```

- **No floating toggle button.** Replaced by shadcn's `SidebarTrigger` in the footer.
- **No `.app-bar`.** The redundant title + date strip is deleted entirely; each page
  supplies its own `.page-header`.
- **Mobile:** shadcn-default behavior — `SidebarTrigger` (hamburger) in a slim top bar
  inside `SidebarInset` opens the sidebar as a left `Sheet`. No custom mobile code.
- **Collapse persistence:** `ui.store.sidebarCollapsed` remains the persistence hook,
  wired to the shadcn primitive's state.

### 3.1 Nav logic migration (must be preserved verbatim)

`AppSidebar.vue` inherits ALL behaviour from the current `AppNav.vue`:

- `navTree` definition (Dashboard, Maintenance Requests group, Work Orders group,
  Assets, Parts, Settings group) with role-`visibleTo` predicates.
- `visibleNodes` role-filtering (`isAdmin`, `isAdminOrManager`, `isTechnician`,
  `isLogistics`, `isRequester`).
- `isLinkActive(to)` — including query-`tab` matching for `?tab=…` routes.
- `isGroupActive(group)` — including the hardcoded path-prefix special cases for
  "Maintenance Requests" (`/maintenance`), "Work Orders" (`/work-orders`),
  "Settings" (`/settings*`).
- `toLocation(to)` — splits `path?query` into a router location object.
- `handleLogout()` — closes mobile sheet, calls `auth.logout()`.
- Mobile `Sheet` open state.

Active state maps onto shadcn's `data-active` attribute on `SidebarMenuButton`.
Sub-items use `SidebarMenuSub` + `SidebarMenuSubButton`.

## 4. Token foundation

`frontend/src/style.css` `:root`:

- **Adopt** the user-supplied light palette verbatim (background, foreground, card,
  popover, primary, secondary, muted, accent, destructive, border, input, ring,
  chart-1..5, sidebar-*, fonts, radius, shadows, spacing).
- **Delete** `@custom-variant dark (&:is(.dark *));` and the entire `.dark { ... }` block.
- **Reuse existing status tokens** (already harmonised with status classes):
  - `--success: oklch(0.530 0.145 142)` / `--success-foreground: oklch(0.985 0 0)`
  - `--warning: oklch(0.700 0.155 75)`  / `--warning-foreground: oklch(0.145 0 0)`
  - `--info:    oklch(0.580 0.138 232)` / `--info-foreground:    oklch(0.985 0 0)`
- **Keep** the `@theme inline { ... }` block so shadcn primitives resolve tokens.
- `--spacing: 0.23rem` accepted as the chosen density (not changed).

Sidebar is light by default: `--sidebar: oklch(0.9940 0 0)` ≈ near-white. Complaint #1 fixed.

## 5. Central class vocabulary

### 5.1 Primitive-replaced (delete the bespoke class, use the primitive)

| Old class                       | Replacement                |
|---------------------------------|----------------------------|
| `.data-card`, `.data-card-*`    | `<Card>` primitive         |
| `.sheet-*`, `.dialog-*` internals | primitives own their internals |
| `.card-grid`                    | generic grid arrangement class |

### 5.2 Keep — arrangement + semantics (rewritten to match shadcn visual language)

- Shell: `.app-shell`
- Page: `.page`, `.page-header`, `.page-title`, `.page-description`, `.page-actions`
- KPI: `.kpi-grid`, `.kpi-card`, `.kpi-card-title`, `.kpi-card-value`,
  `.kpi-card-change`, `.kpi-card-change-positive`, `.kpi-card-change-negative`
- Filters: `.filter-bar`, `.filter-group`, `.filter-actions`
- Table: `.table-container`, `.dense-table`, `.table-actions-cell`, `.pagination-bar`
- States: `.loading-state`, `.empty-state`, `.error-state`, `.permission-state`,
  `.read-only-state`, `.skeleton-grid`
- Icons: `.icon-xs`, `.icon-sm`, `.icon-md`

### 5.3 Frozen — auth pages (do NOT rewrite)

`.atms-auth-page`, `.atms-auth-card`, `.atms-auth-logo[-img]`, `.atms-auth-header`,
`.atms-auth-title`, `.atms-auth-subtitle`, `.atms-auth-form`, `.atms-auth-label-row`,
`.atms-auth-link`, `.atms-auth-error`, `.atms-auth-info`, and friends.

Auth views (`LoginView`, `ActivateView`, `ForgotPasswordView`, `ResetPasswordView`) are
standalone — they do not import `AppLayout` and render outside the shell behind the
router's `meta.public` flag. Their styles are preserved untouched.

### 5.4 Delete — invented shell chrome

`.app-bar`, `.app-bar-title`, `.app-bar-date`, `.app-mobile-topbar`,
`.mobile-topbar-logo`, `.sidebar-toggle-btn` (+ `:hover`, + collapsed override),
the custom `.app-sidebar` / `.sidebar-nav-*` / `.sidebar-user-btn` block (replaced by
the `Sidebar*` primitives), and the duplicate older status set at `style.css:739-754`.

## 6. Status badge system

One `.status-badge` base; status classes map to **documented ATMS statuses only**.
Each renders **text + colour** (never colour alone). Token-driven.

| Domain              | Status                  | Token         |
|---------------------|-------------------------|---------------|
| Maintenance Request | Pending Review          | `--warning`   |
|                     | Converted to Work Order | `--success`   |
|                     | Rejected                | `--destructive` |
|                     | Cancelled               | `--muted`     |
| Work Order          | Open                    | `--info`      |
|                     | In Progress             | `--warning`   |
|                     | Completed               | `--success`   |
|                     | Closed, Cancelled       | `--muted`     |
| ERP Sync            | Running                 | `--info`      |
|                     | Success                 | `--success`   |
|                     | Partial                 | `--warning`   |
|                     | Failed                  | `--destructive` |
| Priority            | critical / high / medium / low | red/amber/blue/gray scale |

Reuses the refined set already at `style.css:1346-1367`; the older duplicate set
(`739-754`) is deleted.

## 7. Migration scope

### 7.1 Rewrite

- `components/app/AppLayout.vue` → new shell using `SidebarProvider` + `SidebarInset`.
- `components/app/AppNav.vue` → `components/app/AppSidebar.vue` using `Sidebar*`
  primitives, carrying all nav logic per §3.1.
- `style.css` — token block (§4), vocabulary (§5), status system (§6).

### 7.2 Mechanical rename pass (all 27 views)

Swap old arrangement classes for new, or swap bespoke class for the matching primitive.
Full view inventory:

- `views/DashboardView.vue`
- `views/admin/`: `AuditLogsView`, `CompanySettingsView`, `EmployeesView`,
  `ErpSyncView`, `ListsView`, `LocationsView`, `MasterDataView`, `SystemSettingsView`,
  `UsersView`
- `views/assets/`: `AssetsView`, `AssetDetailView`
- `views/auth/`: `ActivateView`, `ForgotPasswordView`, `LoginView`, `ResetPasswordView`
  (**frozen — class rename only if absolutely necessary; prefer no touch**)
- `views/errors/`: `ForbiddenView`, `NotFoundView`
- `views/parts/`: `PartDetailView`, `PartsView`
- `views/pm-rules/`: `PmRuleDetailView`, `PmRulesView`
- `views/work-orders/`: `MaintenanceRequestDetailView`, `WorkOrderDetailView`,
  `WorkOrdersListView`, `WorkOrdersView`

### 7.3 Untouched

All composables (`useDashboard`, `useMaintenanceRequests`, `useWorkOrders`,
`useAssetSearch`, `useForgotPassword`, `useResetPassword`, `useActivate`), stores
(`auth.store`, `ui.store`), `lib/` (`api.ts`, `displayHelpers.ts`, `utils.ts`),
`router/index.ts`, types, `components/ui/**`.

### 7.4 Out of scope (flagged for later)

Rewriting `ATMS_UI_RULES.md` to match the relaxed contract in §2.

## 8. Preserved non-negotiables

Even with `ATMS_UI_RULES.md` set aside:

- shadcn-primitives-only in feature code (no raw `<button>/<input>/<select>/<textarea>/<dialog>`).
- Design-tokens-only (no hardcoded values).
- Composable-first (no business logic / fetch in `<script setup>`).
- Light theme only.
- Accessibility (labels, aria, keyboard, focus, status never colour-alone).
- Confirm-before-mutate.

## 9. Notes & known minor items

- **No `Pagination` primitive** in `components/ui/`. `.pagination-bar` stays a pure
  arrangement class over `Button`s. Add the primitive later if server-driven pagination
  controls are needed.
- `--spacing: 0.23rem` is tighter than shadcn's default `0.25rem`; accepted as the
  chosen density and will slightly tighten all `p-*`/`gap-*` inside UI primitives.
- Frontend nav visibility is **not** authorization — the Laravel backend remains
  authoritative (router already enforces `requiresAdmin` / `requiresAdminOrManager`).

## 10. Verification gate (before declaring done)

- `npm run type-check` passes.
- No raw `<button>/<input>/<select>/<textarea>/<dialog>` in feature code.
- No inline `style=` in feature files.
- No Tailwind utilities in feature files (only in `components/ui/`).
- No `.dark` rules or `@custom-variant dark`.
- No hardcoded `#hex`/`oklch()`/`px` in feature code.
- Sidebar renders light; collapse uses `SidebarTrigger` (no floating circle).
- No `.app-bar` title/date strip on any page.
- All documented statuses render text + semantic colour.
- Auth pages render unchanged.
- Mobile: hamburger opens left Sheet drawer.
