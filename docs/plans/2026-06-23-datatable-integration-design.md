# DataTable Integration — adopt `@ioi-dev/vue-table` for all list views

**Date:** 2026-06-23
**Status:** Approved (pending implementation plan)
**Scope:** `frontend/src/**` (list views + a new adapter module + global CSS)

## 1. Goal

Replace the hand-rolled raw `<table class="dense-table">` markup duplicated across every
list view (`WorkOrdersView`, `WorkOrdersListView`, and future lists) with a single
data-table solution built on the already-installed **`@ioi-dev/vue-table`** package.

Concrete problems being solved:

1. **Duplication.** Every list tab re-writes the same boilerplate: `<thead>`, `<tr v-for>`,
   loading/empty/error states, and a "Load more" button. This multiplies across tabs and views.
2. **No sorting or filtering.** Lists are read-only sorted-by-default views; users cannot
   re-sort or filter without the backend round-trip the composables hardcode.
3. **Dead dependency.** `@ioi-dev/vue-table@^0.3.0` is declared and installed but imported
   nowhere. It is a first-party, feature-complete table with a shadcn theme — purpose-built
   for this stack. Re-implementing would duplicate first-party work.

The package is a component, not a raw `<table>`/`<button>` — using it satisfies the
"shadcn-vue primitives only" guardrail (its internal elements live in `node_modules`, like
`components/ui/*` internals).

## 2. Contract (the locked decisions)

- **Adopt `@ioi-dev/vue-table`** as the sole data-table. Use the **`Table`** export
  (alias of `IoiTable`; `DataTable` is also aliased to the same component).
- **Server mode, cursor pagination.** `data-mode="server"` + `serverOptions` with
  `cursorMode: true`. "Load more" via `tableRef.fetchMore()`, shown while `tableRef.hasMore`.
  Matches the Laravel cursor paginator (`next_cursor` / `prev_cursor`, no page numbers).
- **The fetch property is `fetch`, not `query`.** Source-verified: `dataFetching.ts` reads
  `serverOptions.fetch(params)` exclusively. (The README's `query` is stale; the installed
  `.d.ts` types and the source agree on `fetch`.)
- **Shared adapter, not a wrapper component.** One helper (`createCursorSource`) captures
  the lib↔backend translation; columns + endpoint stay per-view. A `<AppDataTable>` wrapper
  is deferred until 2–3 views reveal identical config (YAGNI).
- **Click-to-detail, no inline row actions.** All actions live on the detail page
  (decision). Rows are `@row-click` → `router.push(detailUrl)`.
- **Design tokens preserved.** Global CSS import of `styles.css` + `themes/shadcn.css`;
  the theme maps `--ioi-table-*` to our `var(--…)` oklch tokens directly (no `hsl()`
  wrapper), so the table inherits the design system automatically.
- **Composable-first preserved.** The fetch adapter lives in `lib/`; views are
  orchestration only; cell rendering uses pure `displayHelpers`.

## 3. Backend contract (confirmed)

Both index endpoints (`/maintenance-requests`, `/work-orders`) use **cursor pagination**
and support:

| Capability | Support | Detail |
|---|---|---|
| **Sort** | ✅ | `sort=field:direction`, default `created_at:desc`. Whitelisted; unrecognized → silent fallback. |
| **Per-field filter** | ✅ exact `WHERE =` only | No LIKE, no multi-value, no relationship filtering. |
| **Free-text search (`q=`)** | ❌ | Absent. Would need a new backend feature. |

**Sort whitelists:** MR → `created_at, priority, status`; WO → `created_at, priority, status, started_at, closed_at`.

**Filter params:** MR → `status, asset_id, priority, type, created_by`; WO → `status, assigned_to, asset_id, priority, from, to` (`from`/`to` = `created_at` range).

## 4. v1 scope (ships now) vs deferred

### v1 — everything the backend honestly supports

| Feature | Mechanism |
|---|---|
| Sorting | Clickable headers (`sortable: true` only on whitelisted fields) |
| Column filters | `headerFilter: 'select'` dropdowns → exact-match server params |
| Cursor pagination | `cursorMode: true` + "Load more" button |
| Row interaction | `@row-click` → detail page |

**Select filters wired (small fixed option sets, clean exact-match):**
- MR: **status, priority, type**
- WO: **status, priority**

**Sortable columns:** whitelisted fields only (§3); all others `sortable: false`.

### Deferred (with rationale)

| Feature | Why deferred |
|---|---|
| **Global search** | No backend `q=`. Wiring it would mislead (re-fetch, backend ignores `globalSearch`). Build scoped ILIKE (number + asset name + description, trigram index) later — triggered by notifications going live or dataset growth. |
| **`asset_id` / `created_by` / `assigned_to` filters** | Exact-match on IDs need option-pickers; better as **dedicated pages** (asset history, by-technician view) than table dropdowns. Different information architecture. |
| **WO date range (`from`/`to`)** | Reporting concern → belongs in a dashboard/reports surface, not the operational list. |

The `createCursorSource` adapter is the **single extension point**: adding any deferred
feature later is additive (a column gains a control; the adapter gains a mapping line).

## 5. Architecture

```
main.ts
   import '@ioi-dev/vue-table/styles.css'        ← structure (required)
   import '@ioi-dev/vue-table/themes/shadcn.css'  ← token map (after styles.css)

lib/dataTableSource.ts
   createCursorSource<T>({
     endpoint,        ← '/maintenance-requests' | '/work-orders'
     baseParams?,     ← always-sent (e.g. { status: 'pending_review' } for the Awaiting tab)
     pageSize?,       ← default 25
   }): ServerDataOptions<T>
        └─ .fetch(params) does the lib↔backend translation (§6)

View (e.g. WorkOrdersView.vue, one <Table> per tab)
   <Table
     data-mode="server"
     :server-options="source"
     :columns="columns"
     row-key="id"
     :global-search-debounce-ms="0"   ← search omitted; n/a for v1
     :filter-debounce-ms="300"
     @row-click="(p) => router.push(`/maintenance/requests/${p.row.id}`)"
     ref="tableRef"
   >
     <template #cell="{ column, row, value }"> …badges/links via displayHelpers… </template>
   </Table>
   <Button v-if="tableRef?.hasMore" :disabled="loadingMore" @click="tableRef?.fetchMore()">
     Load more
   </Button>

Composable (useMaintenanceRequests, useWorkOrders)
   keeps ACTION logic (approve/reject/cancel/create)
   DROPS mkList / load* cursor state   ← the table owns rows + cursor now
   after a mutation  →  tableRef.refresh()
```

## 6. The cursor-source adapter — the single lib↔backend translation surface

`createCursorSource` returns a `ServerDataOptions<T>` whose `fetch` maps
`ServerFetchParams` → backend query and `CursorPage<T>` → `ServerFetchResult<T>`.

**Inbound (`ServerFetchParams` → backend query params):**

| Lib field | → backend param | Notes |
|---|---|---|
| `cursor` | `cursor=<v>` | Sent **only on `fetchMore()`** (source-verified). Initial/refresh fetch omits it. |
| `sort[0]` | `sort=field:direction` | First sort state only; backend is single-sort. |
| `filters[]` | per-field exact params | `status=<v>`, `priority=<v>`, `type=<v>` (MR); `status`, `priority` (WO). Values come from `select` filters → plain strings. |
| `globalSearch` | *(ignored)* | No backend support in v1; not wired. |
| `pageSize` | `per_page=<n>` | |
| — (baseParams) | merged in | Tab semantics (e.g. `status: 'pending_review'`). |

**Outbound (`CursorPage<T>` → `ServerFetchResult<T>`):**

```ts
return {
  rows:       res.data,
  nextCursor: res.meta.next_cursor,
  hasMore:    res.meta.next_cursor !== null,
  totalRows:  res.data.length,   // unknown for cursor mode; not used by load-more UX
}
```

The adapter validates filter/sort fields against the backend whitelist defensively, but the
backend's silent fallback makes this belt-and-braces, not load-bearing.

## 7. Column definitions (per view)

Cell content is rendered via the `#cell` slot using existing pure `displayHelpers`
(`mrStatusClass/Label`, `priorityClass/Label`, `mrTypeLabel`, `fmtDate`). No business logic
in templates.

**Maintenance Requests** (all tabs share a base set; tab differs by `baseParams`):
`number` (sortable:false, RouterLink cell) · `asset.name` + `asset.erp_asset_code`
(two-line cell, sortable:false) · `priority` (sortable, `headerFilter:'select'`, badge cell)
· `status` (sortable, `headerFilter:'select'`, badge cell) · `type` (`headerFilter:'select'`)
· `created_at` (sortable, date) · `description` (truncated).

**Work Orders:** analogous — `number`, asset, `priority` (sortable+select), `status`
(sortable+select), `started_at`/`closed_at` (sortable), assignee.

## 8. Migration sequencing

1. **Global CSS wiring** (`main.ts`) + **`createCursorSource`** adapter. No UI yet.
2. **Reference migration:** MR "All Requests" tab → `<Table>` with columns, select filters,
   sort, load-more, row-click→detail. Validate end-to-end against the live backend.
3. **Roll out:** remaining MR tabs (My Requests, Pending Approval) + WO lists.
4. **Cleanup:** delete `mkList`/`load*` from the composables; delete `dense-table`/
   `table-container`/`table-cell-*` markup and now-dead CSS classes from `style.css`.

Each step is independently committable; the app stays working throughout (old tables remain
until their tab is migrated).

## 9. Guardrails compliance

- **Tokens:** theme consumes `var(--…)`; surrounding "Load more" is a shadcn `<Button>`. ✓
- **No raw interactive elements:** `<Table>` is a component; its internals are in
  `node_modules` (like `components/ui/*`). ✓
- **Composable-first:** translation in `lib/`; view orchestrates; cell slot calls pure
  helpers. ✓
- **TypeScript:** `createCursorSource<T>()` is generic; `ColumnDef<T>[]` typed per row;
  no `any` in public signatures (the demos' `as any` casts on `:columns`/`:server-options`
  are a known Vue generic-prop friction point — resolved with a typed wrapper or
  `defineComponent` generics at implementation time, not `any` leaking into our types).
