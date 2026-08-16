# ATMS Frontend Summary

## Application

The SPA is in `frontend/` and uses Vue 3, TypeScript, Vite, Tailwind, shadcn-vue,
and Pinia. The route source of truth is `frontend/src/router/index.ts`; the sidebar
is `frontend/src/components/app/AppSidebar.vue`.

## Main routes

| Area | Current route |
|---|---|
| Dashboard / profile | `/dashboard`, `/profile` |
| Maintenance requests | `/maintenance`, `/maintenance/requests/:requestId` |
| Work orders | `/work-orders`, `/work-orders/:workOrderId` |
| Assets / parts | `/assets`, `/assets/:assetId`, `/parts`, `/parts/:partId` |
| Locations | `/locations` |
| Reports | `/reports` and the individual `/reports/*` pages |
| Administration | `/admin/lists`, `/admin/users`, `/admin/pm-rules`, `/admin/wo-forms` |
| Settings | `/settings/system`, `/settings/audit-logs` |

Public authentication routes are `/login`, `/activate`, `/forgot-password`, and
`/reset-password`. Legacy redirects may remain for compatibility; do not use them
for new navigation.

## Navigation and access

The sidebar is a flat operational list. Dashboard, Maintenance Requests, and
Reports are visible to every authenticated human role. Work Orders and Parts are
shown to Administrators, Maintenance Managers, and Technicians. Asset Management is
shown to Administrators, Maintenance Managers, Technicians, and Logistics.
Locations is shown to Administrators, Maintenance Managers, and Logistics. Admin is
Administrator-only. Server policy checks remain mandatory even when the sidebar
hides an action.

## UI rules

- Reuse the established shadcn-vue components and feature composables before adding
  raw interactive elements.
- Use semantic classes/tokens, visible labels, loading/empty/error states, and
  confirmation for destructive or persistent actions.
- Keep views orchestration-focused; place fetch/mutation logic in composables and
  shared types in `src/types`.
- Respect route metadata and server authorization. Hiding a control is not an
  authorization control.
- Format timestamps in the company timezone. Display unavailable metrics as `—`,
  not `0`, when the API returns `null`.

## Forms, tables, and feedback

- Use side sheets for ordinary create/edit operations, confirmation dialogs for
  short consequential actions, and full pages for MR review and WO execution.
- Every persistent user action requires confirmation after client-side validation.
  The dialog must name the action, summarize the change, disable repeat submission,
  and preserve data if the request fails.
- Tables need documented search/filter behavior, cursor pagination where supplied,
  status badges, loading skeletons, instructional empty states, and responsive
  overflow. Do not show a physical delete where the domain uses cancellation,
  deactivation, or immutable history.
- Put validation errors by their fields, use page/card errors for failed reads, and
  use short toasts for completed operations. Every icon-only control needs an
  accessible label and title.
- Closed/cancelled records are visibly read-only. Avoid disabled controls that look
  actionable when the user cannot perform the operation.

## Traps this codebase has already hit

These are not style preferences — each one shipped a defect.

- **A list that feeds an edit form must load every relation that form submits
  back.** The edit sheet opens from the list row and posts what it was handed, so
  a relation missing from the index query arrives empty and is written back as
  empty. `PmRuleIndexQuery` omitted `maintenanceCategories` and silently wiped a
  rule's category coverage on an unrelated rename.
- **A non-modal sheet leaves the page behind it clickable.** Resetting form state
  only on the open transition is therefore not enough: clicking "Edit" on a second
  row swaps the record while the sheet stays open, and the form keeps the first
  record's values until it writes them over the second one. Re-initialise when the
  edited record's *identity* changes, and exclude the create→edit flip where the
  id goes null → newly-created and what is on screen is already saved.
- **A column holding a list needs array handling in the table's search
  normaliser.** `AppDataTable.toSearchable()` read `.name` off the value; given an
  array it found nothing and the column matched no search at all.
- **A long option list hides the current selection.** `MaintenanceCategoryPicker`
  (shared by WO Forms and PM Rules) pins selected entries to the top and shades
  them, because a purely alphabetical list in a fixed-height box pushes a record's
  own selections below the fold, where they read as "not selected".
- **A `<Label for>` pointing at an id that does not exist fails silently.** It
  neither warns nor throws; the label simply stops working and the field loses its
  accessible name. Check the id against the control it names, especially when the
  control lives inside a child component.
- **Disambiguating vocabulary belongs on the row, not the page title.** "Repair" and
  "Service" are rendered by `mrTypeLabel` in `displayHelpers.ts` (one function, five
  display sites) — deliberately *not* bracketed onto the Maintenance Requests nav or
  page heading, because that list holds both kinds and the PM rule detail page's
  "Generated Maintenance Requests" are entirely preventive. A bracketed title would
  have misdescribed half the rows. `corrective` / `preventive` remain the domain
  terms in the API, the DB, and `MrType`; only the label changed.
- **A parked option must still render for records that already use it.**
  `TRIGGER_OPTIONS` in `PmRuleForm.vue` offers date only, but the edit path shows the
  trigger as read-only text rather than a `Select`, so an existing
  `date_or_reading` rule opens correctly instead of binding to an empty dropdown.
  Removing an option from a list is never sufficient on its own.
- **A config-level TypeScript error stops the whole build.** `vue-tsc --build` exits
  at the first error in `tsconfig.app.json` without checking a single file, so a
  familiar-looking "pre-existing" line can mask every real error underneath it. One
  shipped this way. A clean type-check means **zero output**, not one recognised
  error.

## Feature structure

Views compose domain components and composables; they should not become large API
clients. Keep request types and response types in `src/types`, reusable fetch/mutate
behavior in `src/composables`, and transport concerns in `src/lib/api.ts`. Reuse
the existing UI primitives before adding raw elements or locally hard-coded colors.

## Integration

The API client is `frontend/src/lib/api.ts`. Session hydration is driven by the
auth store. Browser state-changing requests require the Sanctum CSRF flow; client
code must not manufacture role permissions or bypass server validation.

The reports UI is implemented under `frontend/src/views/reports/` and maps to the
active `/api/reports/*` endpoints. Keep report labels and calculations aligned with
[PRODUCT.md](PRODUCT.md) and [API.md](API.md).
