# Frontend UI Rules & Implementation Patterns

A portable rulebook for **Vue 3.5 + shadcn-vue + reka-ui + `@tanstack/vue-table` +
Tailwind v4 + semantic-CSS** apps. Derived from and tested on a production
maintenance-management frontend.

Bracketed items like **[project docs]** are per-project placeholders — define
them once when adopting this for a new project.

Before implementing or reviewing frontend work, also read:

- **[project product / role / workflow docs]**
- **[project screen inventory / design system]**
- **[project UI state conventions]**
- **[project backend API reference]**

When sources conflict, this document should not be silently overridden by
unreviewed visual assets.

---

## 1. Technology Stack & Conventions

| Concern | Choice |
|---|---|
| Framework | Vue 3.5 (`<script setup>` + TypeScript) |
| Build | Vite |
| Routing | Vue Router |
| State (shared only) | Pinia (auth, session, settings) |
| UI primitives | shadcn-vue 2.x + reka-ui |
| Data tables | `@tanstack/vue-table` ^8.x (headless) |
| Icons | Lucide Vue |
| Styling – primitives | Tailwind v4 (inside `src/components/ui/` **only**) |
| Styling – feature code | Semantic CSS classes in one central stylesheet |
| Tokens | CSS custom properties in `:root` |
| Animations | `tw-animate-css` |
| Toasts | vue-sonner |
| Utilities | `@vueuse/core` |

**Feature code** = views, layouts, `components/app/*`, and anything outside
`components/ui/`. Feature code uses **only** semantic CSS classes and shadcn-vue
primitives — never Tailwind utilities, raw `<button>`/`<input>`, inline styles,
or hardcoded hex/px.

---

## 2. The Five Non-Negotiable Rules

### Rule 1: Composable-First

Components are **view + orchestration only**. `<script setup>` may declare
props/emits/model, wire a composable's return to the template, hold trivial
local UI flags, and call composable actions. It must NOT contain data fetching,
business rules, non-trivial transforms, timers, direct DOM/window access, or
duplicated logic → put it in `useXxx()`.

### Rule 2: Semantic CSS Only in Feature Files

In feature Vue files, `class=""` may contain **only** semantic class names
defined in the central stylesheet. No Tailwind utilities, no inline `style=""`,
and no invented one-off class names. Missing a class? **Add it to the central
stylesheet** — do not reach for a utility.

### Rule 3: shadcn-vue / reka-ui Primitives Only

No raw `<button>`, `<input>`, `<select>`, `<textarea>`, or `<dialog>` in
feature code. Use the shared primitives: `Button`, `Input`, `Label`, `Select`,
`Checkbox`, `Sheet`, `Dialog`, `AlertDialog`, `Tabs`, `Tooltip`, `DropdownMenu`,
`Popover` (reka-ui-based), `DatePicker`, `Pagination`, etc. Structural HTML
(`main`, `section`, `nav`, `div`, `p`, `ul`, `li`, `span`) stays allowed.

Missing a primitive? Add it under `components/ui/`, then use it from feature
code. Tailwind utilities are **allowed only** inside `components/ui/`.

### Rule 4: Design Tokens Only

Colors, spacing, typography, radii, borders, shadows, and focus rings in feature
code must reference CSS custom properties (`var(--…)`), never hardcoded values.
Add new values as tokens in `:root` in the central stylesheet.

### Rule 5: Confirmation Before Persistent Change

Every user-initiated mutation (create, edit, approve, reject, cancel, assign,
activate, deactivate, sync, location update, attachment upload/remove) must:
- Validate client-side.
- Open a confirmation dialog that summarises the action.
- Label the confirm button with the exact action (e.g. `"Approve & Create Work
  Order"`, never `"Yes"` or `"OK"`).
- Disable duplicate submission while pending.
- Preserve user input on failure.
- On success, refresh affected data and show a concise toast.

---

## 3. Accessibility Baseline

- Icon-only controls must have a meaningful `aria-label`.
- Every form field must have a visible label.
- Validation errors must be associated with their fields.
- All controls must work by keyboard.
- Focus indicators must remain visible (use `:focus-visible`, not raw `:focus`).
- Dialogs, sheets, and popovers must manage focus correctly.
- Status must never be communicated by colour alone.

---

## 4. Visual Direction & Design Tokens

### Principles

- Enterprise / operational; restrained use of colour; no emojis in UI.
- Clear hierarchy with dense but readable information.
- Consistent cards, forms, tables, and controls.
- Explicit workflow actions; continuously visible record status.
- No decorative complexity at the cost of task clarity.

### Token Foundation

Define all tokens in `:root` in the central stylesheet. Required groups:

- Brand colours (primary, secondary, tertiary)
- Background / surface colours
- Foreground / muted text
- Border / input colours
- Focus ring
- Success, warning, information, destructive states
- Sidebar / navigation
- Typography (font families, weights, sizes)
- Spacing scale
- Border radii (8px for cards, 6px for small controls)
- Shadows / elevation
- Responsive breakpoints

---

## 5. Semantic CSS Architecture

### Central Stylesheet, Unlayered

The project's central stylesheet (e.g. `src/style.css`) defines all semantic
classes **outside** any `@layer`. Tailwind v4 utilities live in the `utilities`
layer; **unlayered rules beat layered rules in the cascade**, so a semantic
class like `.page-header { display: flex; }` automatically wins over any
Tailwind utility on the same element. No `!important` needed for normal
overrides.

### Table CSS — No Third-Party Overrides Needed

TanStack Table is **headless** — it provides state and row models, not markup.
`AppDataTable` renders its own `<table>`/`<thead>`/`<tbody>` with semantic
classes defined in the central stylesheet (`.app-data-table-*`). There are no
third-party table CSS classes to override, so specificity tricks are
unnecessary. If a visual tweak is needed, add or edit a semantic class in the
central stylesheet directly.

### Class Vocabulary

Extend this vocabulary centrally; never invent one-off class names:

| Purpose | Classes |
|---|---|
| Layout | `.app-layout`, `.app-sidebar`, `.app-main`, `.app-content` |
| Page | `.page-section`, `.page-header`, `.page-heading`, `.page-title`, `.page-subtitle`, `.page-actions` |
| Cards | `.card-grid`, `.data-card`, `.data-card-header`, `.data-card-title`, `.data-card-content`, `.data-card-actions` |
| Detail | `.detail-back`, `.detail-meta`, `.detail-text`, `.detail-section`, `.detail-actions` |
| Table shell | `.app-data-table`, `.app-data-table-scroll`, `.app-data-table-table`, `.app-data-table-thead`, `.app-data-table-th`, `.app-data-table-sort-btn`, `.app-data-table-sort-icon`, `.app-data-table-filter-row`, `.app-data-table-filter-cell`, `.app-data-table-tbody`, `.app-data-table-row`, `.app-data-table-cell`, `.app-data-table-empty`, `.data-table-toolbar`, `.data-table-search`, `.data-table-pagination`, `.data-table-pagination-info`, `.data-table-pagination-subtle`, `.data-table-pagination-controls`, `.data-table-pagination-nav`, `.data-table-pagination-page`, `.data-table-pagination-size`, `.table-filter-trigger` |
| Table cells | `.table-cell-stack`, `.table-cell-primary`, `.table-cell-secondary`, `.table-cell-truncate`, `.table-link` |
| Combobox | `.asset-combobox-trigger`, `-value`, `-placeholder`, `-caret`, `-panel`, `-search`, `-search-icon`, `-search-input`, `-list`, `-option`, `-option-name`, `-option-code`, `-check`, `-empty` |
| Forms | `.form-grid`, `.form-field`, `.form-field-full`, `.form-help`, `.form-error`, `.form-actions` |
| Sheets | `.create-sheet`, `-header`, `-body`, `-footer` |
| States | `.status-badge`, `.loading-state`, `.empty-state`, `.error-state`, `.permission-state`, `.read-only-state` |

---

## 6. Standard Page Pattern

Every primary page contains:

1. A clear page title (`.page-title`)
2. A concise description (`.page-subtitle`)
3. Actions permitted for the current role (`.page-actions`)
4. Search / filters inside a `data-table-toolbar`
5. An `AppDataTable` (see §7) or card/form content
6. Loading, empty, error, and permission-aware states

```vue
<template>
  <section class="page-section">
    <header class="page-header">
      <div class="page-heading">
        <h1 class="page-title">Work Orders</h1>
        <p class="page-subtitle">Review and manage active maintenance work.</p>
      </div>
      <div class="page-actions">
        <Button v-if="canCreate" @click="createOpen = true">New Work Order</Button>
      </div>
    </header>

    <div class="view-tabs">
      <!-- RouterLink tabs -->
    </div>

    <AppDataTable :key="activeTab" :rows="rows" :columns="columns" … />
  </section>
</template>
```

---

## 7. Data Table Pattern (AppDataTable)

Use `@tanstack/vue-table` (headless) through a **single generic shared shell**
(`AppDataTable`) — do not rebuild sorting, filtering, or pagination per-feature.
The shell renders its own `<table>` markup with semantic classes; TanStack
provides only state + row models (core, filtered, sorted, pagination).

### 7.1 Generic SFC + Typed `#cell` Slot

The `AppDataTable` component is a generic SFC (`generic="TRow"`) so each view
gets fully-typed cell rendering with zero consumer-side casts:

```vue
<!-- AppDataTable.vue -->
<script setup lang="ts" generic="TRow">
```

TanStack's `useVueTable` is not generic-friendly enough to infer `TRow` through
the wrapper, so a boundary cast is used inside the shell:

```ts
type AnyRow = Record<string, unknown>
```

The `#cell` slot re-asserts `TRow` so every consumer gets typed cells:

```vue
<template #cell="{ column, row, value }">
  <span v-if="column.field === 'priority'" :class="priorityClass(row.priority)">
    {{ priorityLabel(row.priority) }}
  </span>
</template>
```

### 7.2 Column Definitions (Framework-Agnostic)

Columns are declared with the local `AppColumnDef<T>` type
(`src/lib/appTable.ts`) — **never** import TanStack's `ColumnDef` in a column
module. `AppDataTable` maps `AppColumnDef` → TanStack's `ColumnDef` internally:

```ts
import type { AppColumnDef as ColumnDef } from '@/lib/appTable'

export const mrColumns: ColumnDef<MaintenanceRequest>[] = [
  { field: 'number', header: 'Request', sortable: true },
  { field: 'priority', header: 'Priority', sortable: true, headerFilter: 'select' },
  // …
]
```

Fields: `field` (row property + column key), `header`, `sortable`,
`headerFilter: 'select' | 'text'`, `type: 'date' | 'number' | 'text'`,
`minWidth` (px), `comparator` (custom sort).

### 7.3 Global Search (Debounced)

Search is owned by the shell. The toolbar `Input` feeds a debounced
`globalFilter` (200 ms) into TanStack's global filter, which matches across all
columns. A custom `globalFilterFn` normalizes object values (e.g. `asset.name`)
to a searchable string so relations are matchable.

### 7.4 Header Filters (Select + Text)

Column filters use TanStack's `columnFilters` state. A `select` filter uses
`filterFn: 'equals'` (exact match); a `text` filter uses `'includesString'`. The
header renders a shadcn `<Select>` or `<Input>` bound to the column's filter
value. Filter options come from the `filterOptions` prop
(`Record<field, FilterOption[]>`).

### 7.5 Client-Side Sort with Comparator

For object fields (e.g. `asset` which is `{ name, code }`), provide a
`comparator` to sort by the nested value. The shell adapts it to TanStack's
`sortingFn` signature:

```ts
export const mrColumns: ColumnDef<MaintenanceRequest>[] = [
  { field: 'asset', header: 'Asset', sortable: true,
    comparator: (a, b) => {
      const an = (a as { name?: string } | null)?.name ?? ''
      const bn = (b as { name?: string } | null)?.name ?? ''
      return an.localeCompare(bn)
    },
  },
]
```

Multi-sort is enabled (shift-click adds to the sort stack). Sort direction is
shown via chevron icons (asc / desc / unsorted) in the header.

### 7.6 Pagination — "Showing X to Y of Z" + "All" Option

The shell renders a custom pagination bar with a First/Prev/Next/Last chevron
cluster (shadcn `Button size="icon-sm"`) and a page-size `<Select>` that offers
`10 / 50 / 100 / All`. The info line shows the current page range over the
**filtered** total (not the unfiltered total):

```vue
<span class="data-table-pagination-info">
  Showing {{ pageInfo.start }} to {{ pageInfo.end }} of {{ pageInfo.total }}
  <span v-if="hasActiveFilters" class="data-table-pagination-subtle">
    ({{ pageInfo.originalTotal }} total)
  </span>
</span>
```

```ts
const filteredCount = computed(() => table.getFilteredRowModel().rows.length)
const pageInfo = computed(() => {
  const { pageIndex, pageSize } = pagination.value
  const total = filteredCount.value
  return {
    start: total === 0 ? 0 : pageIndex * pageSize + 1,
    end: Math.min((pageIndex + 1) * pageSize, total),
    total,
    originalTotal: totalCount.value,
  }
})
```

Map "All" to a huge page size (`const ALL_ROWS = 100_000`) so all rows render on
one page. Changing page size resets to page 1 (standard UX).

### 7.7 State Persistence Across Navigation

The shell caches sort / column filters / global search / page-size per
`route.path + label` key in a module-level `Map`. On revisit, these are
restored; `pageIndex` is intentionally NOT restored (the table resets it on
filter/sort change — re-applying it fights that reset).

### 7.8 Row Actions — Icon-Only

Per-row action controls in a table's `actions` column are **icon-only** — never
text-labelled buttons. This keeps action columns compact and visually uniform
across every table in the app. A text button in a row action column is a bug.

Rules:
- Wrap the controls in a `<div class="table-row-actions">`.
- Each control is a `Button` with `size="icon-sm"` and a single Lucide icon child.
- The **primary** action (e.g. Edit) uses `variant="outline"`; **secondary**
  actions (toggle active, reset password, delete, view history) use
  `variant="ghost"`.
- Every icon button **must** carry a meaningful, row-specific `aria-label`
  (e.g. `:aria-label="\`Edit ${row.name}\`"`) — icon alone is not accessible
  (see §3).
- Disable (don't hide) actions that are contextually unavailable, so the column
  stays aligned (e.g. self-action guard in the users table).
- A **status** that looks like a chip (e.g. a "Provisioned" badge) is not an
  action — render it as a `.status-badge`, not a `Button`.

Canonical icons: Edit → `Pencil`; activate/deactivate → `ToggleLeft`/`ToggleRight`;
delete → `Trash2`; reset password → `KeyRound`; provision/add user → `UserPlus`;
view history → `History`; update location → `MapPin`.

```vue
<div v-else-if="column.field === 'actions'" class="table-row-actions">
  <Button variant="outline" size="icon-sm" :aria-label="`Edit ${row.name}`" @click="openEdit(row)">
    <Pencil />
  </Button>
  <Button variant="ghost" size="icon-sm" :aria-label="`Deactivate ${row.name}`" @click="openToggle(row)">
    <ToggleRight />
  </Button>
</div>
```

Reference implementations: `ManageLocationsView.vue`, `AssetLocationUpdateView.vue`,
`admin/UsersView.vue`, `admin/ListsView.vue`.

---

## 8. Combobox / Searchable Selector Pattern

### 8.1 Building Blocks

shadcn-vue does not ship a monolithic `Combobox` — it's a composition of
**reka-ui Popover + search Input + option list**.

Required primitives (`components/ui/popover/` based on reka-ui):
- `Popover` (wraps `PopoverRoot` + `useForwardPropsEmits`)
- `PopoverTrigger` (with `as-child`)
- `PopoverContent` (wraps `PopoverPortal` + positioning + animations)

The trigger is a `Button` (`role="combobox"` + `aria-haspopup="listbox"`);
the content contains an `Input` (search) + option `Button`s (`role="option"`).

### 8.2 Asynchronous Backend Search

Keep the debounced fetch in a composable (`useXxxSearch`), returning
`{ query, results, busy, search, loadInitial, reset }`. The combobox
component only owns popover open-state + `v-model` selection + keyboard nav
(view orchestration).

### 8.3 THE CRITICAL RULE — Portaled Widget (Popover / Select / DropdownMenu) Inside a Modal Sheet / Dialog

A reka-ui **modal** `Dialog`/`Sheet` activates three mechanisms. Any widget that
**portals its content to `<body>`** — `Popover`/Combobox, **`Select`**,
`DropdownMenu` — sits outside the dialog's DOM and trips them:

1. **`useHideOthers`** — sets `aria-hidden="true"` on everything outside the
   dialog content. A widget portaled to `<body>` gets `aria-hidden`, making any
   focusable element inside it inaccessible. When the dialog's own trigger still
   holds focus, the browser blocks it and warns: *"Blocked aria-hidden on an
   element because its descendant retained focus"*.
2. **Focus trap** (`FocusScope` with `trapped: true`) — bounces focus back
   into the dialog. The portaled popover input is outside the dialog's DOM, so
   focus never lands there.
3. **`disableOutsidePointerEvents`** — sets `document.body.style.pointerEvents
   = "none"`; the portaled popover (at `<body>` level) inherits this, becoming
   click-dead.

**The fix that works** (and the one that failed):

| Approach | Verdict |
|---|---|
| Make the **Sheet non-modal** (`:modal="false"`) | ✅ Works — removes all three blocking mechanisms. The overlay/X/Esc still close it. Trade-off: loses focus trap (Tab escapes the sheet). Acceptable for form sheets. |
| Make the **Popover modal** (`<Popover modal>`) | ❌ Fails — `useHideOthers` from the Sheet STILL hides the portaled popover (aria-hidden war), even with the FocusScope stack pause. The browser blocks the popover's input. |

**Two sanctioned fixes — pick by overlay type:**

| Overlay | Fix | Why |
|---|---|---|
| **Sheet** (create/edit form, usually scrollable) | `:modal="false"` on the `Sheet` | Removes all three mechanisms. Loses the focus trap (Tab can leave the sheet) — acceptable for forms. Keeps the portal, so dropdowns are **never clipped** by the sheet's `overflow-y: auto` body. This is the default for any Sheet with a portaled widget. |
| **Modal Dialog** (short, non-scrolling) | Keep it modal; render the widget **inline** instead of portaled | Inline content lives *inside* the dialog DOM, so `useHideOthers`, the focus trap, and `disableOutsidePointerEvents` all treat it as in-scope. Preserves the focus trap (better a11y for a true modal). |

For `Select`, the inline route is a first-class prop — **`<SelectContent disable-portal>`** (added to `components/ui/select/SelectContent.vue`; it sets `<SelectPortal :disabled>`). Use it for a `Select` inside a **modal `Dialog`**.

> ⚠️ **`disable-portal` forces `position="popper"` (do not override).** reka-ui's
> default `item-aligned` positioning assumes the content is teleported to
> `<body>`; rendered inline it can't compute its offset and **jumps to a screen
> corner** (top-left/right). `SelectContent` therefore auto-switches to `popper`
> (floating-ui, anchored to the trigger) whenever `disable-portal` is set — the
> two are inseparable, so callers don't pass `position` alongside it.

> ⚠️ **`disable-portal` clipping caveat.** An inline (non-portaled) dropdown is
> laid out within the overlay's DOM, so it can be **clipped by an `overflow`
> ancestor** (e.g. a scrolling `.create-sheet-body`). Only use `disable-portal`
> in a **non-scrolling** overlay (a short confirm-style Dialog). For a scrolling
> Sheet, prefer `:modal="false"` and keep the portal.

**Reference implementations:** Assign-Rule picker → `Select` in a modal Dialog
uses `disable-portal` ([`AssetPmSection.vue`](frontend/src/components/assets/AssetPmSection.vue)).
PM template form → `Select`s in a scrolling Sheet use `:modal="false"`
([`PmRuleForm.vue`](frontend/src/components/pm-rules/PmRuleForm.vue)).

---

## 9. Form & Overlay Patterns

### Side Sheets

Use for ordinary create/edit forms. Use the **non-modal** convention (see §8.3)
if the sheet contains any portaled widget.

### Dialogs

Use for persistent-change confirmations, reject/cancel/deactivate actions,
terminal workflow actions, and short actions requiring a reason.

### Full Pages (Not Sheets)

Use full pages when:
- The user needs surrounding context while acting.
- The record must be deep-linkable (e.g. a detail page with its own URL).
- The workflow involves multiple steps or review-then-act.

**Example:** a "Review + Edit + Approve" detail page that loads a record at
`/[entity]/[id]`, showing read-only data with an inline Edit toggle, plus
workflow action buttons (Approve, Reject, Cancel).

Full pages should include a **Back button** that returns to the previous list
(`router.back()`) and preserves the active tab (via `?tab=` URL query).

### Form Layout

- Two columns on larger screens for naturally-related fields; collapse to one
  column on small screens.
- Long text and complex controls stay full-width.
- Show required state (`*`) and optional state (`— optional`).
- Errors next to the affected field.
- Preserve entered data on failed submission.
- Disable duplicate submit while pending.

---

## 10. Confirmation Flow

All mutations follow this sequence:

1. User completes the form or selects an action.
2. Client-side validation runs.
3. On success, open a confirmation dialog summarising the intent.
4. Confirmation button labels the **exact action** (e.g. `"Approve Request"`,
   never `"Yes"` or `"OK"`).
5. Explain consequences for terminal / hard-to-reverse actions.
6. Disable repeated submission while pending.
7. On success: refresh affected data + concise toast.
8. On failure: preserve input + show actionable field/system errors.

---

## 11. Feedback & UI States

Every data-driven view supports: initial loading, background refresh, empty
result, filtered-empty, validation error, request failure, unauthorised /
forbidden, and read-only terminal state.

### Expected HTTP Status Handling (generic)

| Code | Meaning | Action |
|---|---|---|
| 400 | Invalid request | Show field or system error |
| 401 | Unauthenticated | Return to authentication flow |
| 403 | Unauthorised | Show a permission-aware state |
| 404 | Not found | Show "not found" state |
| 409 | Domain conflict | Explain why the transition is unavailable |
| 422 | Validation failed | Show errors next to relevant fields |
| 5xx | Server error | Show recoverable error, retain user data |

### Toast Status Colours

vue-sonner toasts get a `data-type` attribute (`success`, `error`, `warning`,
`info`). Target it from the central stylesheet with on-brand tokens:

```css
[data-sonner-toaster] [data-sonner-toast][data-type="success"] {
  background: var(--success);
  color: var(--success-foreground);
  border-color: transparent;
}
/* … error → var(--destructive), warning → var(--warning), info → var(--info) */
```

---

## 12. Domain Model & Statuses

> **[Per-project: define your statuses, transitions, and display logic here.**
> **Do not add undocumented statuses. Colour alone is not sufficient — every**
> **status badge must show readable text.]**

---

## 13. Responsive Behaviour

- Replace desktop nav with a mobile pattern (e.g. bottom nav or hamburger).
- Collapse multi-column grids: 4→2→1.
- Collapse two-column forms to one column.
- Keep primary actions reachable; preserve critical workflow context.
- Tables: responsive overflow or a shared small-screen representation.
- Do not silently drop important columns or actions.
- Sheets and dialogs must fit the viewport with their action area accessible.

**Sheet size convention** (adopt per-project):

```css
/* Mobile-first: near full screen */
.create-sheet { width: 100% !important; max-width: 100% !important; }
/* Tablet */
@media (min-width: 768px) { .create-sheet { width: 92vw !important; } }
/* Desktop */
@media (min-width: 1024px) { .create-sheet { width: 68rem !important; } }
```

---

## 14. Banned Patterns

| Pattern | Rule |
|---|---|
| Raw `<button>`/`<input>`/`<select>`/`<textarea>`/`<dialog>` in feature files | Use shadcn-vue primitives |
| Tailwind utilities in feature Vue files | Use semantic classes |
| Inline `style=""` in feature files | Use semantic classes or design tokens in the central stylesheet |
| Hardcoded `#hex` / `oklch()` / `px` in feature files | Use design tokens (`var(--…)`) |
| Business logic in `<script setup>` | Extract to `useXxx()` composable |
| Fetch / `axios` / `window` / direct DOM access in `<script setup>` | Put in composable |
| `any` in props, emits, or composable returns | Use explicit types |
| Pinia store holding single-feature state | Store only for auth/session/settings/cross-component shared state |
| `:focus` outlines that linger after clicks | Use `:focus-visible` |
| "Just one Tailwind class for layout" | One becomes ten — add a semantic class |
| Dark-mode toggles / `@media (prefers-color-scheme: dark)` | Light-theme only unless explicitly required by the project spec |

---

## 15. Review Checklist

Before completing UI work, verify:

- [ ] Feature Vue files contain no Tailwind utilities, inline `style`, or raw interactive elements.
- [ ] Interactive controls use shared shadcn-vue / reka-ui components.
- [ ] Data tables go through the shared `AppDataTable` shell.
- [ ] All visual values reference design tokens.
- [ ] Persistent changes require confirmation; button labels name the exact action.
- [ ] Failed mutations preserve entered data.
- [ ] Loading, empty, error, permission, and read-only states are implemented.
- [ ] Icon-only actions have accessible labels.
- [ ] Form fields have labels and associated errors.
- [ ] Keyboard and `:focus-visible` behaviour works.
- [ ] `:modal="false"` on any Sheet containing a portaled widget.
- [ ] Mobile / narrow-screen behaviour has been checked.
- [ ] No undocumented statuses, transitions, or features were added.
- [ ] Backend authorisation remains authoritative.

---

## 16. Quick Reference

```
UI primitives:              components/ui/
Global styles + tokens:     src/style.css
Application views:          src/views/
Shared app components:      components/app/
Composables:                composables/
Router:                     src/router/
Stores (shared only):       src/stores/
Test                    :     npm run type-check  /  npm run build
Format                  :     npm run format
Dev server              :     npm run dev
```
