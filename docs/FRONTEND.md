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
