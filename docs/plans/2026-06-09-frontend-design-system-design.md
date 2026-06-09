# Frontend Design System Incorporation

## Context

An older Vue 3 and shadcn-vue design-system document was reviewed for reuse in
ATMS. Its product-specific examples are not authoritative for ATMS, but its
component discipline, semantic styling, accessibility, token architecture, and
shared layout patterns are reusable.

## Approved Decisions

- ATMS MVP is light-theme only.
- ATMS reuses the company's deep navy, deep rose, and deep purple palette.
- Interactive feature controls use shared shadcn-vue components.
- Feature Vue files contain no Tailwind utility classes.
- Shared tokens, semantic CSS classes, and shared components establish visual
  consistency across pages.
- Side sheets contain ordinary create and edit forms.
- Dialogs handle confirmations and short consequential actions.
- Complex workflow review and execution remain full-page experiences.
- Every user-initiated persistent change requires confirmation, including
  profile and settings edits.
- Validation occurs before confirmation. Failed writes preserve form data.
- Backend permissions remain authoritative.

## Adaptation Boundary

Adopt from the old document:

- Tokenized light-theme foundation
- Company palette
- Shared component usage
- Semantic CSS classes
- Standard page, table, form, loading, empty, error, and responsive patterns
- Accessibility requirements

Do not copy from the old document:

- Product entities, workflows, permissions, or statuses
- Generic `Delete` behavior where ATMS uses cancellation or deactivation
- `Reopened` or other statuses absent from ATMS
- Page-specific examples that conflict with ATMS documentation

The durable implementation authority is
`docs/02-design/UI_DESIGN_SYSTEM.md`.
