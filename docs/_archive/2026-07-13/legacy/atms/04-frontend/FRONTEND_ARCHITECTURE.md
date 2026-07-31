# Frontend Architecture

## Locked Frontend Stack

- Vue 3
- TypeScript
- Tailwind CSS
- shadcn-style component approach
- API-driven frontend consuming Laravel backend

## Frontend Principles

- Keep navigation simple.
- Use list → detail → action patterns.
- Keep workflow actions explicit.
- Avoid ERP-like complexity.
- Show status badges clearly.
- Use consistent terminology across screens.
- Use the light-theme company design tokens defined in
  `docs/02-design/UI_DESIGN_SYSTEM.md`.
- Use shared shadcn-vue components for interactive controls.
- Do not use Tailwind utility classes in feature Vue files; use shared semantic
  classes.
- Require confirmation before every user-initiated persistent change.

## Suggested Structure

```text
src/
  app/
    router.ts
    layouts/
  components/
    ui/
    forms/
    tables/
    status/
  features/
    dashboard/
    assets/
    assembly/
      (assembly-tree, assembly-history, install-component, etc.)
    parts/
    locations/
      (update-location-sheet, location-list, location-form)
    maintenance-requests/
    work-orders/
    admin/
    settings/
    attachments/
  services/
    api.ts
    auth.ts
  stores/
    auth.store.ts
    ui.store.ts
  types/
```

## Routing

Main routes. Tab state is driven by `?tab=` query parameters.

```text
/dashboard                  Dashboard (direct link)
/maintenance                Maintenance Requests (tabbed: new-request, my-requests,
                            pending-approval, all-requests)
/work-orders                Work Orders (tabbed: my, all, active, completed, closed)
/work-orders/:id            Work Order Detail (full-page drill-down)
/assets                     Asset Management (tabbed: all, assembly)
/assets/:id                 Asset Detail (drill-down)
/parts                      Parts Management (tabbed: all, part-request)
/parts/:id                  Part Detail (drill-down)
/locations                  Locations (tabbed: asset-location-update, manage-locations)
/admin                      Admin (tabbed: users, lists, pm-rules)
/settings                   Settings (tabbed: system, audit-logs)
```

Tabs are role-filtered at the view level — a tab that is not visible to the
current user's role is hidden from the tab bar.

## State Management

Use simple API-query state initially. Add Pinia only for shared state such as auth/session, global settings, and UI preferences.

## API Client

Create a single typed API client wrapper for HTTP requests.

Handle:

- Auth token/session
- Error formatting
- Validation errors
- Pagination
- File uploads
