# DataTable Integration Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace all hand-rolled raw `<table>` list markup with `@ioi-dev/vue-table`, gaining server-side sorting + exact-match column filters + cursor "load more" pagination that maps onto the Laravel cursor-paginated backend.

**Architecture:** One shared `createCursorSource<T>()` adapter (`lib/`) translates `ServerFetchParams`↔backend query and `CursorPage<T>`↔`ServerFetchResult<T>`. Each list view renders `<Table data-mode="server">` with per-view `ColumnDef[]` + a `#cell` slot (pure `displayHelpers`). The list composables lose their `mkList`/`load*` cursor state (the table owns it) and their inline row actions (moved to the detail page per design decision); they keep the create flow.

**Tech Stack:** Vue 3.5 `<script setup>` + TypeScript, `@ioi-dev/vue-table@^0.3.0` (component `Table`; server fn property is **`fetch`**, source-verified), shadcn-vue primitives, design tokens via `var(--…)`.

**Verification available (no frontend test runner):** `npm run type-check` (vue-tsc), `npm run format` (oxfmt), `npm run build`, manual smoke via `npm run dev`.

**Design doc:** `docs/plans/2026-06-23-datatable-integration-design.md` (read first; it has the verified API facts and backend contract).

---

## Critical pre-flight facts (do not re-derive)

- **`fetch`, not `query`.** `serverOptions.fetch(params)` is the only property the runtime reads.
- **Cursor is sent only on `fetchMore()`.** Initial/refresh fetch has `params.cursor === undefined`. Adapter must omit the `cursor` query param when undefined.
- **CSS:** import BOTH `@ioi-dev/vue-table/styles.css` (structure) AND `@ioi-dev/vue-table/themes/shadcn.css` (token map). Our oklch tokens flow through unchanged.
- **No `any` in our types.** `createCursorSource<T>()` returns a properly-typed `ServerDataOptions<T>`, so `:server-options="source"` needs no cast. If vue-tsc's generic-prop inference on `<Table>` ever complains, cast at the binding site using the real type (`as ServerDataOptions<X>`), never `as any`.
- **Backend:** sort `sort=field:dir` (whitelist MR: created_at/priority/status; WO: created_at/priority/status/started_at/closed_at). Filters exact `=` only (MR: status/asset_id/priority/type/created_by; WO: status/assigned_to/asset_id/priority/from/to). No `q=`.
- **`globalSearch` is NOT wired** (no backend). Do not add a search input.

---

### Task 1: Global CSS wiring + the `createCursorSource` adapter

**Files:**
- Modify: `frontend/src/main.ts` (add 2 CSS imports after `import './style.css'`)
- Create: `frontend/src/lib/dataTableSource.ts`

**Step 1: Wire the table CSS globally**

In `frontend/src/main.ts`, after the line `import './style.css'`, add:

```ts
import '@ioi-dev/vue-table/styles.css'
import '@ioi-dev/vue-table/themes/shadcn.css'
```

(Order matters: `styles.css` is structure; `themes/shadcn.css` maps `--ioi-table-*` onto our tokens and must come after.)

**Step 2: Create the adapter**

Create `frontend/src/lib/dataTableSource.ts`:

```ts
import api from '@/lib/api'
import type { CursorPage } from '@/types'
import type {
  ServerDataOptions,
  ServerFetchParams,
  ServerFetchResult,
  SortState,
} from '@ioi-dev/vue-table'

export interface CursorSourceOptions {
  /** API path, e.g. '/maintenance-requests' or '/work-orders'. */
  endpoint: string
  /** Always-sent query params (tab semantics, e.g. { status: 'pending_review' }). */
  baseParams?: Record<string, string | number | boolean>
  /** Page size. Default 25. */
  pageSize?: number
}

/**
 * Build a `ServerDataOptions<T>` whose `fetch` maps the ioi-vue-table server
 * contract onto our Laravel cursor-paginated endpoints. This is the SINGLE
 * lib<->backend translation surface (see design doc §6).
 *
 * Cursor note: `params.cursor` is undefined on the initial/refresh fetch and
 * populated only on `fetchMore()` (source-verified), so we omit it when absent.
 */
export function createCursorSource<T>(
  options: CursorSourceOptions,
): ServerDataOptions<T> {
  const { endpoint, baseParams = {}, pageSize = 25 } = options

  async function fetch(params: ServerFetchParams): Promise<ServerFetchResult<T>> {
    const query: Record<string, string | number | boolean> = {
      ...baseParams,
      per_page: params.pageSize || pageSize,
    }

    // Cursor — only present on load-more.
    if (params.cursor) query.cursor = params.cursor

    // Sort — backend is single-sort; take the primary sort state.
    const primary: SortState | undefined = params.sort?.[0]
    if (primary) query.sort = `${primary.field}:${primary.direction}`

    // Filters — exact-match only. Each FilterState carries a typed filter;
    // for our `select` filters the value is a plain string.
    for (const f of params.filters) {
      const v = extractFilterValue(f.filter)
      if (v !== null && v !== '') query[f.field] = v
    }

    const res = await api.get<CursorPage<T>>(endpoint, query)

    return {
      rows: res.data,
      nextCursor: res.meta.next_cursor,
      hasMore: res.meta.next_cursor !== null,
      totalRows: res.data.length,
    }
  }

  return {
    fetch,
    cursorMode: true,
    initialPageSize: pageSize,
    debounceMs: 300,
  }
}

/** Pull a scalar backend value out of a typed ColumnFilter. */
function extractFilterValue(
  filter: ServerFetchParams['filters'][number]['filter'],
): string | number | null {
  switch (filter.type) {
    case 'text':
      return filter.value
    case 'number':
      return filter.operator === 'between'
        ? null // date/range filters are deferred; select filters never produce these
        : (filter.value ?? null)
    case 'date':
      return null // deferred (WO from/to belongs in a reports surface)
    default:
      return null
  }
}
```

**Step 3: Verify**

Run: `npm run type-check`
Expected: PASS (no errors). If `api.get`'s signature differs, adapt the call to match `frontend/src/lib/api.ts` (it returns the parsed body; `CursorPage<T>` is the body shape).

Run: `npm run build`
Expected: builds; the two CSS imports resolve from `node_modules`.

**Step 4: Commit**

```bash
git add frontend/src/main.ts frontend/src/lib/dataTableSource.ts
git commit -m "feat(frontend): wire ioi-vue-table CSS; add createCursorSource adapter"
```

---

### Task 2: Maintenance Request column definitions

**Files:**
- Create: `frontend/src/lib/mrColumns.ts`

**Step 1: Define the shared MR columns**

Create `frontend/src/lib/mrColumns.ts`. These render via a `#cell` slot in the view, so the `ColumnDef` only declares structure (field/header/sortable/headerFilter); the view owns cell rendering.

```ts
import type { ColumnDef } from '@ioi-dev/vue-table'
import type { MaintenanceRequest } from '@/types'

/**
 * Column definitions shared by all Maintenance Request list tabs.
 * Sortable fields are limited to the backend whitelist
 * (created_at, priority, status). Cell rendering happens in the view's #cell slot.
 */
export const mrColumns: ColumnDef<MaintenanceRequest>[] = [
  { field: 'number', header: 'Request', sortable: false },
  { field: 'asset', header: 'Asset', sortable: false },
  { field: 'priority', header: 'Priority', sortable: true, headerFilter: 'select' },
  { field: 'status', header: 'Status', sortable: true, headerFilter: 'select' },
  { field: 'type', header: 'Type', sortable: false, headerFilter: 'select' },
  { field: 'created_at', header: 'Created', sortable: true, type: 'date' },
  { field: 'description', header: 'Description', sortable: false },
]
```

Notes for the executor:
- `asset` is a nested object (`asset.name`, `asset.erp_asset_code`); the `#cell` slot reads `row.asset.name`. The `field: 'asset'` is only a column identity for the table; the slot does the actual rendering.
- `number` is not whitelisted for sort → `sortable: false`.
- No `headerFilter` on `number`/`asset`/`description` (no backend support; not wired).

**Step 2: Verify & commit**

Run: `npm run type-check` → PASS.
```bash
git add frontend/src/lib/mrColumns.ts
git commit -m "feat(frontend): add Maintenance Request column definitions"
```

---

### Task 3: Refactor `useMaintenanceRequests` — sources, drop list actions, keep create

**Files:**
- Modify: `frontend/src/composables/useMaintenanceRequests.ts`

**Context:** Per design decision (b), the list has **no inline row actions** — approve/reject/cancel live on the detail page (`useMaintenanceRequestDetail`). The list composable therefore: (1) exposes a `createCursorSource` per tab instead of `mkList`/`load*`; (2) deletes its own approve/reject/cancel + dialogs; (3) keeps the create flow; (4) exposes nothing about cursor/loading state (the table owns it).

**Step 1: Replace the three `mkList` blocks + `loadAllRequests`/`loadAwaiting`/`loadMyRequests` with three sources**

Delete the `mkList` helper and the `allMr`/`ar`/`mr` list objects and their `load*` functions. Replace with:

```ts
import { createCursorSource } from '@/lib/dataTableSource'
import type { MaintenanceRequest } from '@/types'
import type { ServerDataOptions } from '@ioi-dev/vue-table'

// inside useMaintenanceRequests():
const allMrSource: ServerDataOptions<MaintenanceRequest> = createCursorSource<MaintenanceRequest>({
  endpoint: '/maintenance-requests',
  baseParams: { sort: 'created_at:desc' },
})

const awaitingSource: ServerDataOptions<MaintenanceRequest> = createCursorSource<MaintenanceRequest>({
  endpoint: '/maintenance-requests',
  baseParams: { status: 'pending_review', sort: 'created_at:asc' },
})

const myRequestsSource: ServerDataOptions<MaintenanceRequest> = createCursorSource<MaintenanceRequest>({
  endpoint: '/maintenance-requests',
  baseParams: { created_by: auth.user?.id ?? 0, sort: 'created_at:desc' },
})
```

(Keep the `auth` reference already in the composable. `created_by` defaults safely to `0` if user is somehow absent; the table simply won't match — same as today.)

**Step 2: Delete the approve/reject/cancel blocks**

Remove `approveTarget/approveOpen/approveLoading/openApprove/doApprove`, `rejectTarget/.../doReject`, `cancelTarget/.../doCancel` and their `Dialog` state entirely. These actions are owned by `useMaintenanceRequestDetail` (already implemented on the detail page).

**Step 3: Keep the create flow; refresh the My Requests table after create**

The create flow (`assetSearch`, `createOpen`, `doCreate`, etc.) stays. `doCreate` currently calls `loadMyRequests(false, true)` at the end — replace that with nothing inside the composable (the view will refresh its My Requests `<Table>` ref after create). So `doCreate`'s tail becomes just `closeCreate()`. The view owns the post-create refresh (Task 4).

**Step 4: Update the return object**

Return only what the list view still uses:

```ts
return {
  allMrSource,
  awaitingSource,
  myRequestsSource,
  assetSearch,
  createOpen, confirmCreateOpen, createLoading, createPriority, createDescription,
  attachFiles, addFiles, removeFiles,
  canCreate,
  requestCreate, doCreate, closeCreate,
}
```

**Step 5: Verify & commit**

Run: `npm run type-check`.
Expected: ERRORS in `WorkOrdersView.vue` (it still references `ar`/`mr`/`allMr`/`load*`/`openApprove` etc.) — that is EXPECTED; the view is migrated in Task 4. Do not fix the view here.

```bash
git add frontend/src/composables/useMaintenanceRequests.ts
git commit -m "refactor(frontend): useMaintenanceRequests serves cursor sources; drop list actions (now on detail)"
```

(Commit despite the view being temporarily broken — Task 4 fixes it immediately. The build is only re-verified after Task 4.)

---

### Task 4: Migrate `WorkOrdersView.vue` (all 3 MR tabs → `<Table>`)

**Files:**
- Modify: `frontend/src/views/work-orders/WorkOrdersView.vue`

This is the reference migration; the WO view (Task 6) mirrors it.

**Step 1: Update the `<script setup>` imports & wiring**

- Import `Table` from `@ioi-dev/vue-table`, `Button` (already present), `mrColumns` from `@/lib/mrColumns`, and the `displayHelpers` already in use (`mrStatusClass`, `mrStatusLabel`, `priorityClass`, `priorityLabel`, `mrTypeLabel`, `fmtDate`).
- Destructure from `useMaintenanceRequests()`: `allMrSource`, `awaitingSource`, `myRequestsSource`, plus the create-flow bindings. Remove all references to `ar`/`mr`/`allMr`/`loadAwaiting`/`loadMyRequests`/`loadAllRequests`/`openApprove`/`openReject`/`openCancel` and their dialogs.
- Add a template ref per tab so the view can refresh after create:

```ts
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Table } from '@ioi-dev/vue-table'
import { mrColumns } from '@/lib/mrColumns'
import { mrStatusClass, mrStatusLabel, priorityClass, priorityLabel, mrTypeLabel, fmtDate } from '@/lib/displayHelpers'
import { useMaintenanceRequests } from '@/composables/useMaintenanceRequests'
// …AppLayout, Button, Dialog (create), Label, Textarea, Select, etc. stay

const router = useRouter()
const { allMrSource, awaitingSource, myRequestsSource, /* create-flow bindings */ , doCreate, closeCreate } = useMaintenanceRequests()

const myRequestsTable = ref<InstanceType<typeof Table> | null>(null)
function goToDetail(row: { id: number | string }) {
  router.push(`/maintenance/requests/${row.id}`)
}
```

**Step 2: Replace each tab's `<table class="dense-table">…</table>` block with a `<Table>`**

For each of the three tabs, replace the entire `<div class="table-container">…<table>…</table>…Load-more button</div>` block with:

```vue
<Table
  data-mode="server"
  :server-options="SOURCE_FOR_THIS_TAB"
  :columns="mrColumns"
  row-key="id"
  :filter-debounce-ms="300"
  :height="480"
  aria-label="Maintenance requests"
  @row-click="(p: { row: { id: number | string } }) => goToDetail(p.row)"
  :ref="(el) => { if (tab.key === 'my-requests') myRequestsTable = el as InstanceType<typeof Table> | null }"
>
  <template #cell="{ column, row, value }">
    <RouterLink
      v-if="column.field === 'number'"
      :to="`/maintenance/requests/${row.id}`"
      class="table-link"
    >{{ row.number }}</RouterLink>

    <span v-else-if="column.field === 'asset'">
      <span class="table-cell-primary">{{ row.asset?.name }}</span>
      <span class="table-cell-secondary">{{ row.asset?.erp_asset_code }}</span>
    </span>

    <span v-else-if="column.field === 'priority'" :class="priorityClass(row.priority)">
      {{ priorityLabel(row.priority) }}
    </span>

    <span v-else-if="column.field === 'status'" :class="mrStatusClass(row.status)">
      {{ mrStatusLabel(row.status) }}
    </span>

    <span v-else-if="column.field === 'type'">{{ mrTypeLabel(row.type) }}</span>

    <span v-else-if="column.field === 'created_at'">{{ fmtDate(row.created_at) }}</span>

    <span v-else-if="column.field === 'description'" class="table-cell-truncate">
      {{ row.description ?? '—' }}
    </span>

    <template v-else>{{ value }}</template>
  </template>

  <template #empty>No maintenance requests found.</template>
</Table>

<Button
  variant="outline"
  size="sm"
  :disabled="false"
  class="table-load-more"
  @click="REF_FOR_THIS_TAB?.fetchMore()"
>Loading more…</Button>
```

Per-tab substitutions (build these by reading the existing tab `v-if`/`v-else-if` branches):
- Pending Approval tab → `:server-options="awaitingSource"`, ref `awaitingTable`, empty text `"No requests awaiting approval."`, aria-label `"Requests awaiting approval"`.
- My Requests tab → `:server-options="myRequestsSource"`, ref `myRequestsTable`, empty text `"You haven't submitted any requests yet."`.
- All Requests tab → `:server-options="allMrSource"`, ref `allRequestsTable`, empty text `"No maintenance requests found."`.

**"Load more" visibility:** show the button only when more rows exist. Each tab needs its own ref typed `InstanceType<typeof Table> | null`; bind with a function ref or `ref="awaitingTable"` etc. and render:

```vue
<Button v-if="awaitingTable?.hasMore" variant="outline" size="sm" @click="awaitingTable?.fetchMore()">
  Load more
</Button>
```

(`hasMore` is exposed on the component instance per the `.d.ts` `expose` block.)

**Step 3: Remove the deleted dialogs & inline action buttons**

Delete the approve/reject/cancel `<Dialog>` blocks and the inline action `<td class="table-cell-actions">` cells — they no longer exist in the composable. The create `<Dialog>`/`<Sheet>` stays.

**Step 4: Post-create refresh**

In the create confirm handler (where `doCreate` is called), after it resolves, refresh the My Requests table:

```ts
async function confirmCreate() {
  await doCreate()
  myRequestsTable.value?.refresh()
}
```

(Wire this where the view currently invokes `doCreate`.)

**Step 5: Verify**

Run: `npm run type-check` → PASS (this fixes the break from Task 3).
Run: `npm run format`.
Run: `npm run build` → PASS.

Manual smoke (`npm run dev`): open each MR tab → rows load, sort by clicking Created/Priority/Status headers, filter via the Status/Priority/Type select dropdowns, "Load more" appends a page, clicking a row opens the detail page, creating a request refreshes My Requests.

**Step 6: Commit**

```bash
git add frontend/src/views/work-orders/WorkOrdersView.vue
git commit -m "feat(frontend): migrate Maintenance Request list tabs to ioi-vue-table"
```

---

### Task 5: Work Order column definitions + refactor `useWorkOrders`

**Files:**
- Create: `frontend/src/lib/woColumns.ts`
- Modify: `frontend/src/composables/useWorkOrders.ts`

**Step 1: WO columns**

Create `frontend/src/lib/woColumns.ts` (sortable fields per WO whitelist: created_at/priority/status/started_at/closed_at):

```ts
import type { ColumnDef } from '@ioi-dev/vue-table'
import type { WorkOrder } from '@/types'

export const woColumns: ColumnDef<WorkOrder>[] = [
  { field: 'number', header: 'Work Order', sortable: false },
  { field: 'asset', header: 'Asset', sortable: false },
  { field: 'priority', header: 'Priority', sortable: true, headerFilter: 'select' },
  { field: 'status', header: 'Status', sortable: true, headerFilter: 'select' },
  { field: 'assigned_to', header: 'Assigned', sortable: false },
  { field: 'started_at', header: 'Started', sortable: true, type: 'date' },
  { field: 'closed_at', header: 'Closed', sortable: true, type: 'date' },
]
```

(Verify the `WorkOrder` type field names in `frontend/src/types/index.ts` — adjust `assigned_to`/`started_at`/`closed_at`/`asset` to match the actual type. `assigned_to` is not given a `headerFilter` because it's an ID needing a picker — deferred.)

**Step 2: Refactor `useWorkOrders`**

Apply the same transformation as Task 3: replace each `mkList`/`load*` with a `createCursorSource<WorkOrder>` per tab; delete any list-level actions that now belong on a WO detail page; keep create if present. Return the sources.

**Step 3: Verify & commit**

Run: `npm run type-check` (expect errors in `WorkOrdersListView.vue` — fixed in Task 6).

```bash
git add frontend/src/lib/woColumns.ts frontend/src/composables/useWorkOrders.ts
git commit -m "refactor(frontend): useWorkOrders serves cursor sources; add WO column definitions"
```

---

### Task 6: Migrate `WorkOrdersListView.vue` (WO tabs → `<Table>`)

**Files:**
- Modify: `frontend/src/views/work-orders/WorkOrdersListView.vue`

**Step 1–4:** Mirror Task 4 exactly, substituting `woColumns`, `woSources`, the WO detail route (`/work-orders/${row.id}`), and WO cell rendering (use WO-appropriate `displayHelpers`: status/priority labels, assignee name, dates). Remove inline row actions if any (rows are click-to-detail only).

**Step 5: Verify**

Run: `npm run type-check` → PASS. `npm run format`. `npm run build` → PASS.
Manual smoke: each WO tab loads, sorts (incl. started_at/closed_at), filters by status/priority, loads more, click → WO detail.

**Step 6: Commit**

```bash
git add frontend/src/views/work-orders/WorkOrdersListView.vue
git commit -m "feat(frontend): migrate Work Order list tabs to ioi-vue-table"
```

---

### Task 7: Delete dead CSS & markup

**Files:**
- Modify: `frontend/src/style.css`

**Step 1: Remove now-unused table classes**

After Tasks 4 & 6, nothing references `.dense-table`, `.table-container`, `.table-row-clickable` (the `Table` component manages its own rows/click via `@row-click`). Grep to confirm zero usages, then delete those rules from `style.css`.

KEEP: `.table-link`, `.table-cell-primary`, `.table-cell-secondary`, `.table-cell-truncate`, `.table-cell-secondary` — these are still used inside the `#cell` slot. KEEP `.table-load-more` if you added it (or omit; the shadcn `<Button>` is enough).

Run: `grep -rnE "dense-table|table-container|table-row-clickable" frontend/src/` → expect **no matches** before deleting.

**Step 2: Verify & commit**

Run: `npm run build` → PASS.
```bash
git add frontend/src/style.css
git commit -m "chore(frontend): remove dead dense-table CSS after DataTable migration"
```

---

### Task 8: Final verification

**Step 1: Full check**

```bash
npm run type-check   # PASS
npm run format       # applies oxfmt
npm run build        # PASS
```

**Step 2: Purity gate (guardrails)**

```bash
# No raw interactive elements, no inline styles, no Tailwind utilities, no hardcoded values in the new/changed feature files:
grep -rnE "<(button|input|select|textarea|dialog)\b" frontend/src/views/work-orders/WorkOrdersView.vue frontend/src/views/work-orders/WorkOrdersListView.vue   # none
grep -rnE 'style="' frontend/src/views/work-orders/WorkOrdersView.vue frontend/src/views/work-orders/WorkOrdersListView.vue                                     # none
grep -rnE "#[0-9a-fA-F]{3,8}\b|[0-9]+px\b" frontend/src/lib/dataTableSource.ts frontend/src/lib/mrColumns.ts frontend/src/lib/woColumns.ts                      # none
```

**Step 3: Manual smoke checklist**

- [ ] MR: each tab loads first page; sort headers (Created/Priority/Status) flip asc/desc.
- [ ] MR: Status/Priority/Type select filters narrow results (exact-match, server-side).
- [ ] MR: "Load more" appends; button hides when `hasMore` is false.
- [ ] MR: row click → detail page; detail approve/reject/cancel still work.
- [ ] MR: create request → My Requests refreshes.
- [ ] WO: same checks; sort includes Started/Closed.
- [ ] Table visually matches shadcn tokens (light theme, correct borders/hover).

**Step 4: Final commit (if format changed anything)**

```bash
git add -A
git commit -m "chore(frontend): format after DataTable migration" || echo "nothing to commit"
```

---

## Notes for the executor

- **Read the design doc first** (`docs/plans/2026-06-23-datatable-integration-design.md`) — it has the verified API facts and the rationale for every decision above.
- **`fetch`, never `query`.** If you instinctively write `query`, stop.
- **One `<Table>` per tab.** Tabs are `v-if`'d; each tab gets its own `:server-options` and its own template ref for `fetchMore`/`hasMore`/`refresh`.
- **No `any`.** The adapter is generic; trust its return type. If vue-tsc struggles with `<Table>`'s generic props on `:columns`/`:server-options`, cast with the real type at the binding site.
- **Field names:** confirm against `frontend/src/types/index.ts` (`MaintenanceRequest`, `WorkOrder`) before finalizing column `field` values.
- **If a `#cell` field name doesn't match a `ColumnDef.field`,** the cell falls through to the default `{{ value }}` — harmless but check each column renders correctly during smoke.
