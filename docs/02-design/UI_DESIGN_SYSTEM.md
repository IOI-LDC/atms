# UI Design System

## Authority and Scope

This document is the visual and interaction standard for the ATMS frontend.
Product scope, workflows, roles, permissions, and data behavior remain defined
by the product and backend documentation. This UI design system serves as the
visual and interaction standard for the ATMS frontend.

## Design Principles

- Light theme only for MVP.
- Use the existing company palette: deep navy primary, deep rose secondary, and
  deep purple accent.
- Keep the interface operational, restrained, and consistent.
- Use shared components and patterns instead of page-specific controls.
- Make workflow actions explicit and statuses continuously visible.
- Meet keyboard, focus, labeling, and error-association accessibility needs.

## Mandatory Implementation Rules

### Shared Interactive Components

Use shadcn-vue components for interactive controls whenever an equivalent
component exists. Feature code must not directly create raw `button`, `input`,
`select`, `textarea`, or `dialog` controls.

### Semantic Styling

Feature Vue files must not contain Tailwind utility classes. Visual styling
lives in the shared CSS layer and is exposed through semantic classes such as:

- `app-layout`
- `page-header`
- `page-actions`
- `kpi-grid`
- `data-card`
- `dense-table`
- `filter-bar`
- `form-grid`
- `form-actions`
- `loading-state`
- `empty-state`
- `error-state`

The shared `components/ui/` implementation may use Tailwind internally.

### Design Tokens

Define visual values centrally in the frontend CSS token layer. Components and
semantic classes must consume tokens instead of hard-coded visual values.

Required token groups:

- Company colors and semantic status colors
- Background, card, text, muted text, border, and focus ring
- Spacing scale
- Typography scale and weights
- Border radius
- Elevation
- Responsive breakpoints

Initial company color tokens:

```css
:root {
  --primary: 221.4 32% 17.5%;
  --secondary: 349.4 59% 31.2%;
  --tertiary: 262.1 21% 32.5%;
  --background: 0 0% 100%;
  --foreground: 222.2 84% 4.9%;
  --muted: 210 40% 96%;
  --border: 214.3 31.8% 91.4%;
  --destructive: 0 84.2% 60.2%;
}
```

Status tokens must be mapped to documented ATMS statuses. Do not import
statuses from older systems, such as `Reopened`, unless ATMS documentation adds
them.

## Standard Page Pattern

Every primary page uses:

1. Page title and concise purpose text
2. Role-permitted page actions
3. Search and filters where the data set needs them
4. Main cards, table, form, or detail sections
5. Loading, empty, error, and permission-aware states

Use one shared role-adaptive page set. Hide unavailable content and actions
according to the current user's role, while treating backend authorization as
authoritative.

## Tables

Use a shared dense data-table pattern with:

- Search and documented filters
- Pagination
- Clear status badges
- Explicit row actions
- Responsive overflow behavior
- Loading skeletons
- Instructional empty states

Do not show physical delete actions where ATMS uses cancellation,
deactivation, account deactivation, or immutable history.

## Forms and Overlays

- Use side sheets for ordinary create and edit forms.
- Use dialogs for confirmations, rejection, cancellation, deactivation, and
  other short consequential decisions.
- Use full pages for complex review and execution workflows, including
  Maintenance Request Review and Work Order Detail.
- Use two-column forms on larger screens and one column on mobile.
- Show validation errors next to their fields.
- Preserve entered data after a failed request.

## Persistent-Change Confirmation Policy

Every user-initiated action that can persist a database or stored-file change
requires confirmation. This includes:

- Creating or editing records
- Profile and company settings edits
- Assignments and reassignments
- Workflow status changes
- Approval, rejection, cancellation, completion, and closure
- Activation, deactivation, and reactivation
- ERP synchronization
- Location and meter-reading changes
- Attachment submission or removal

Read-only navigation, search, filtering, pagination, tab changes, and local
draft state do not require confirmation. Automated backend jobs do not prompt a
user; their results must appear in the relevant status or activity history.

Confirmation flow:

1. Validate the form before opening the confirmation dialog.
2. Summarize the intended changes in plain language.
3. Use the exact action as the confirm label, such as `Save Changes`,
   `Update Location`, or `Close Work Order`.
4. Explain consequences for terminal, destructive, or difficult-to-reverse
   actions.
5. Disable repeat submission while the request is in progress.
6. On failure, keep the form data and show actionable field or system errors.
7. On success, refresh affected data and show a concise toast.

## Feedback and States

- Use skeletons or structured loading placeholders for initial data loading.
- Use inline field errors for validation failures.
- Use a readable page or card error for failed data loading.
- Use toasts for concise operation outcomes.
- Use instructional empty states that describe the next permitted action.
- Keep closed and cancelled records visibly read-only.
- Keep visible focus indicators and meaningful keyboard order.
- Give every icon-only control an accessible label.

## Responsive Behavior

- Replace the desktop sidebar with a mobile navigation trigger.
- Collapse KPI grids from four columns to two and then one.
- Collapse two-column forms to one column.
- Preserve table access with responsive overflow or an equivalent shared
  pattern.
- Keep primary actions reachable without hiding critical workflow context.
