# Semantic Class Catalog (Portable Baseline)

Reference for Guardrail 2. This is a **portable baseline** vocabulary — reuse it across projects and extend it **centrally** (in the project's main stylesheet, e.g. `src/style.css`) when a new reusable pattern appears. Never invent one-off classes inside a feature `.vue`.

## Naming Convention

- Lowercase, hyphenated, **semantic** — describe purpose, not appearance: `page-header`, `data-card` — not `flex-row` or `blue-box`.
- Block–element–modifier shape: `block`, `block-element`, `block-modifier` / `block-element-modifier` (e.g. `kpi-card-change-positive`).
- Express state/variant via modifier classes, never utilities: `view-tab-active`, `status-open`.
- One concept = one class; compose in markup:

```html
<div class="data-card">
  <header class="data-card-header">
    <h3 class="data-card-title">Title</h3>
  </header>
  <div class="data-card-content">…</div>
</div>
```

## Extending the Catalog

1. You need a visual structure with no matching class.
2. **Do not** write a utility or inline workaround in the feature file.
3. Add the semantic class + its rules to the central stylesheet using **tokens** (`var(--…)`).
4. Use the new class name in markup.

If the same ad-hoc structure appears twice, it is now a pattern — promote it to a named class.

## Baseline Vocabulary

### Application Layout

Navigation is project-specific — **ask the user**; use either a top nav or a sidebar, not both.

- Top nav: `app-layout` · `app-topnav` (or `app-topbar`) · `app-header` · `app-main` · `app-content` · `mobile-navigation-trigger`
- Sidebar: `app-layout` · `app-sidebar` · `app-header` (or `app-bar`) · `app-main` · `app-content` · `mobile-navigation-trigger`

### Page Structure
`page-section` · `page-header` · `page-heading` · `page-title` · `page-subtitle` · `page-actions` · `page-content`

### Cards & Metrics
`card-grid` · `data-card` · `data-card-header` · `data-card-title` · `data-card-description` · `data-card-content` · `data-card-actions`

`kpi-grid` · `kpi-card` · `kpi-card-header` · `kpi-card-title` · `kpi-card-value` · `kpi-card-change` · `kpi-card-change-positive` · `kpi-card-change-negative`

### Filters & Tables
`filter-bar` · `filter-group` · `filter-actions`

`table-container` · `dense-table` · `table-primary-cell` · `table-secondary-text` · `table-actions-header` · `table-actions-cell` · `table-action-button` · `pagination-bar`

### Forms
`form-grid` · `form-section` · `form-section-header` · `form-field` · `form-field-full` · `form-help` · `form-error` · `form-actions`

### Sheets & Dialogs
`sheet-panel` · `sheet-body` · `sheet-footer` · `confirmation-summary` · `confirmation-warning` · `dialog-actions`

### Status & Feedback
`status-badge` · `loading-state` · `empty-state` · `error-state` · `permission-state` · `read-only-state` · `skeleton-grid`

Status modifiers are **semantic to the domain**, one per real status — never color-only: `status-open`, `status-in-progress`, `status-completed`, `status-closed`, `status-cancelled`, `status-rejected`, `status-pending-review`, …

Priority badges follow the same rule: `priority-critical`, `priority-high`, `priority-medium`, `priority-low`.

### Icons
`icon-xs` · `icon-sm` · `icon-md` · `icon-lg`

### Detail Views (optional)
`detail-header` · `detail-title-block` · `detail-title` · `detail-meta` · `detail-actions` · `detail-section` · `detail-section-title` · `detail-field-grid` · `detail-field` · `detail-field-label` · `detail-field-value`

## Token Bridge (reference)

The central stylesheet exposes tokens in `:root` and bridges them into Tailwind v4 via:

```css
@theme inline {
  --color-primary: var(--primary);
  --radius-md: var(--radius);
  /* … */
}
```

This keeps **one source of truth**: feature code uses `var(--token)` and semantic classes; only `components/ui/` consumes the Tailwind color utilities. Add new design values as tokens in `:root`, then bridge them in `@theme inline` if a primitive needs them.

Theme/token sets are typically sourced from [tweakcn.com](https://tweakcn.com) and applied to the `:root` layer via the shadcn/tweakcn MCP. When a project already defines tokens, ask the user whether to **keep** them or **replace** them before changing the token layer.
