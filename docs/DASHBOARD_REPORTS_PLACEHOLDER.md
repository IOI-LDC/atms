# Dashboard & Reports — placeholder hold (pending LDC requirements)

**Status:** Active (temporary)
**Date:** 2026-07-19
**Scope:** Frontend only. No backend changes.

## Why

The current Dashboard and Reports were built on ATMS-team assumptions, before LDC
provided their own requirements. Until LDC hands over their requirements, the
client-facing `/dashboard` and `/reports` must **not** show those assumption-based
pages. The real pages and all their logic are **kept intact**, not deleted — they are
simply not what `/dashboard` and `/reports` render for now.

## What the user sees now

| Route | Renders | Notes |
|---|---|---|
| `/dashboard` | **Interim placeholder** | Header with **New MR** / **Locations** buttons → three live counter cards at the top (Pending MR, Open Work Orders, Overdue PM, from the real `GET /api/dashboard`) → an "Awaiting LDC requirement receipt" panel at the bottom (same style as Reports). |
| `/reports` | **Placeholder** | "Awaiting LDC requirement receipt" panel only. |
| `/dashboard-real` | The real, full dashboard (`DashboardView.vue`) | Unchanged. Reachable by URL; **not** linked in the sidebar. |
| `/reports-real` | The real reports index (`ReportsView.vue`) | Unchanged. Reachable by URL; **not** linked in the sidebar. |
| `/reports/:slug` | Individual report pages | Unchanged — all still work. |

Sidebar links are unchanged: **Dashboard → `/dashboard`**, **Reports → `/reports`**
(both now land on the placeholders).

## Exact changes

**New files**
- `frontend/src/components/app/PendingRequirementPlaceholder.vue` — shared "pending
  requirement" panel (icon + badge + message).
- `frontend/src/views/DashboardPlaceholderView.vue` — interim dashboard (header
  actions + counter cards on top + shared placeholder panel at the bottom), consumes
  `useDashboard()` + `useQuickActions()`.
- `frontend/src/views/reports/ReportsPlaceholderView.vue` — reports placeholder.

**Modified files**
- `frontend/src/router/index.ts` — `/dashboard` and `/reports` now import the
  placeholder views; added `/dashboard-real` and `/reports-real` pointing at the real
  views.
- `frontend/src/composables/useQuickActions.ts` — added an optional `only` allowlist
  param (`useQuickActions(['New MR', 'Locations'])`); role-gating unchanged, fully
  backward compatible.
- `frontend/src/style.css` — added `.pending-placeholder*` and `.pending-cards` /
  `.pending-card*` semantic classes.

**Untouched (preserved)**
- `frontend/src/views/DashboardView.vue`, `frontend/src/views/reports/ReportsView.vue`,
  `frontend/src/components/app/AppSidebar.vue`, and **all backend code / API routes**.

## How to revert

### If NOT yet committed (restore the working tree)

```bash
cd /Users/rawandhawez/Desktop/LDC/atms
git restore frontend/src/router/index.ts \
            frontend/src/composables/useQuickActions.ts \
            frontend/src/style.css
rm frontend/src/components/app/PendingRequirementPlaceholder.vue \
   frontend/src/views/DashboardPlaceholderView.vue \
   frontend/src/views/reports/ReportsPlaceholderView.vue
cd frontend && npm run build   # confirm clean
```

`/dashboard` and `/reports` return to the real views; the `-real` routes disappear.

### If already committed (recommended: keep it as one self-contained commit)

```bash
git revert <commit-sha>        # undoes the whole change in one step
```

### Manual revert (if the change was mixed into a larger commit)

1. `router/index.ts`: point `/dashboard` back to `@/views/DashboardView.vue` and
   `/reports` back to `@/views/reports/ReportsView.vue`; delete the `/dashboard-real`
   and `/reports-real` route entries.
2. Delete the three new files listed under **New files** above.
3. `style.css`: remove the `.pending-placeholder*` and `.pending-card*` /
   `.pending-cards` blocks.
4. *(Optional)* `useQuickActions.ts`: remove the `only` param — harmless to leave.
5. `cd frontend && npm run build` to confirm.

## When LDC's requirements arrive

Build the real Dashboard/Reports to match LDC's requirements (adapting or replacing
`DashboardView.vue` / `ReportsView.vue`), then either point `/dashboard` and
`/reports` back at the real views (removing the placeholders) or fold the approved
design into them. Delete this document once the hold is lifted.
