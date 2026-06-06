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
    parts/
    maintenance-requests/
    work-orders/
    pm-rules/
    admin/
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

Main routes:

```text
/dashboard
/assets
/assets/:id
/work-orders
/work-orders/requests/:id
/work-orders/:id
/parts
/parts/:id
/pm-rules
/pm-rules/:id
/admin/users
/admin/locations
/admin/master-data
/admin/erp-sync
```

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
