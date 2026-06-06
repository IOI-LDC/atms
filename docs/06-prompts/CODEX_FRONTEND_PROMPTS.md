# Codex Frontend Prompts

## Prompt: Create Vue Frontend Skeleton

Create the Vue 3 + TypeScript frontend skeleton for ATMS using Tailwind and shadcn-style components.

Main navigation:

- Dashboard
- Assets
- Work Orders
- Parts Reference
- PM Rules
- Administration

Set up routes, layouts, API client, auth store, and placeholder pages.

## Prompt: Build Asset Registry UI

Build Asset Registry and Asset Detail screens.

Requirements:

- Asset list with search, filters, status badge, current location, latest reading.
- Asset detail with tabs: Overview, Usage & Meter Readings, Location History, Maintenance History, Attachments, ERP Reference Data.
- Use clean operational labels.
- Do not include financial asset fields except read-only ERP reference fields if provided.

## Prompt: Build Work Orders Module UI

Build Work Orders module with tabs:

- Pending Requests
- Active Work Orders
- Closed Work Orders

Requirements:

- Pending Requests list.
- Review Request screen with Approve & Create Work Order and Reject Request actions.
- Active Work Order detail with notes, parts used, attachments, updated reading, final status, close action.
- Closed Work Order detail as read-only history.

## Prompt: Build PM Rules UI

Build PM Rules list and form.

Requirements:

- List active/inactive PM rules.
- Create/edit PM rules.
- Deactivate/reactivate PM rules; do not physically delete them.
- Support date, reading, and date_or_reading trigger types.
- Show last completed and next due information where available.

## Prompt: Build Administration UI

Build Administration area.

Sections:

- Users & Roles
- Locations
- Master Data
- ERP Sync Settings
- ERP Sync History

Keep the UI simple and operational.
