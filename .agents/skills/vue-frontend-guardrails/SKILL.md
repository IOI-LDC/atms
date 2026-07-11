---
name: vue-frontend-guardrails
description: Use when writing or reviewing Vue 3 frontend code — .vue components, composables, stores, styles, or views — in a Vue 3.5 + TypeScript + Vite + Tailwind v4 + shadcn-vue + Pinia stack. Triggers on creating/editing Vue files, writing composables, adding classes or styles, building forms/tables/dialogs, or when you notice Tailwind utilities, raw interactive elements, logic-heavy components, or hardcoded colors in feature code.
---

# Vue Frontend Guardrails

## Overview

This skill is a **guardrail enforcer**, not a tutorial. Its job is to stop the most common Vue + Tailwind + shadcn-vue mistakes before they ship.

**The contract — four places, four rules:**

- **Logic** → composables (`useXxx.ts`), never `<script setup>` business logic.
- **Styling** → semantic CSS classes in the central stylesheet, never Tailwind/inline in feature files.
- **Interactions** → shadcn-vue primitives, never raw `<button>/<input>/<select>/<textarea>/<dialog>`.
- **Values** → design tokens (CSS custom properties), never hardcoded hex/oklch/px in feature code.

## When to Use

Use when creating or reviewing **any** frontend file in the stack above:

- a `.vue` file (view, layout, `components/app`, `components/ui`)
- a composable, store, or style
- a form, table, dialog, sheet, or page section

**Do NOT use** for: backend code, non-Vue projects, or when a project has its own UI-rules doc that conflicts (that doc wins — see Conflict Order).

## Conflict Order

When sources disagree, this wins, top to bottom:

1. The project's own UI-rules / design-system doc (e.g. `ATMS_UI_RULES.md`, `UI_DESIGN_SYSTEM.md`) and authoritative product/backend docs.
2. This skill.
3. Screenshots / mockups — visual reference only, never a source of rules, fields, or status names.

## Project Setup — Ask, Don't Assume

These are project-specific choices. Ask the user; never assume or hardcode a preference. Ask only when the choice is actually in scope (not on every file edit).

1. **Navigation — Top Nav or Sidebar?** Do not assume a layout. When establishing or changing the application's primary navigation, ask the user which pattern the project uses: a **top navigation bar** (`app-topnav`) or a **left sidebar** (`app-sidebar`). Implement only the chosen one and keep `.app-main` / `.app-content` consistent with it.

2. **Design tokens — keep or replace?** If the main stylesheet (`src/style.css` or equivalent) already defines tokens in `:root`, do not silently overwrite them. When you need to introduce or change tokens, ask the user whether to **keep** the existing tokens or **replace** them. Themes/token sets are typically sourced from [tweakcn.com](https://tweakcn.com) and applied via the shadcn/tweakcn MCP. When replacing, apply the chosen theme to the `:root` token layer and keep the `@theme inline` bridge intact so primitives and feature code share one source of truth.

## The Five Non-Negotiable Guardrails

### 1. Composable-first, strict separation

Components are **view + orchestration only**. `<script setup>` may: declare props/emits/model, wire a composable's return to the template, hold trivial local UI flags (open/closed, active tab), and call composable actions.

`<script setup>` must NOT contain: data fetching, business rules, non-trivial transforms/formatting, timers, direct DOM or `window` access, or duplicated logic. → Put it in a `useXxx()` composable.

**Smell:** a `<script setup>` longer than its template, or any `fetch`/`watch` doing real work.

### 2. Semantic CSS only in feature files

**Feature files** = views, layouts, `components/app/*`, and anything outside `components/ui/`.

In feature files, `class=""` may contain **only** semantic class names defined in the central stylesheet. Banned in feature files:

- Tailwind utility classes (`flex`, `gap-4`, `text-sm`, `rounded-lg`, …)
- Inline `style="..."`
- Arbitrary one-off class names invented on the spot

Tailwind utilities are allowed **only** inside `components/ui/` (the primitives).

Missing a class? **Add a semantic class to the central stylesheet** — do not reach for a utility. See `semantic-class-catalog.md` for the portable baseline.

### 3. shadcn-vue primitives only

Feature code must not use raw `<button>`, `<input>`, `<select>`, `<textarea>`, or `<dialog>`. Use the shared components: `Button`, `Input`, `Label`, `Select`, `Checkbox`, `Sheet`, `Dialog`, `AlertDialog`, `Tabs`, `Tooltip`, `DropdownMenu`, `DatePicker`, `Pagination`, …

Structural HTML (`main`, `section`, `header`, `nav`, `article`, `div`, `p`, `ul`, `li`, `span`) remains allowed.

Missing a primitive? **Add it under `components/ui/`** (Tailwind lives there), then use it.

### 4. Design tokens only

Colors, spacing, typography, radii, borders, shadows, and focus rings in feature code must reference CSS custom properties (`var(--…)`), not hardcoded values. No `#hex`, raw `oklch(...)`, or magic `px` in feature files. Add new values as tokens in `:root` in the central stylesheet.

### 5. Stack & state discipline

- Vue 3.5 `<script setup>` + TypeScript. Options API only if a file already uses it — don't mix.
- Pinia stores are for **auth, session, settings, and genuinely cross-component shared state only**. Component-local state and feature logic live in the component or a composable — not a global store.

## Secondary Rules (apply, lighter weight)

- **TypeScript:** typed props/emits via generics (`defineProps<T>()`), typed `defineModel<T>()`, discriminated-union types for statuses, no `any` in public signatures.
- **Forms & async states:** every data view supports loading / empty / filtered-empty / error / forbidden / read-only. Preserve user input on failure, disable duplicate submit while pending, confirm before any persistent mutation; label the confirm button with the exact action.
- **Accessibility:** every field has a visible label; icon-only buttons have `aria-label`; all controls work by keyboard; dialogs/sheets manage focus; status is never communicated by color alone.

See `vue3-idioms.md` for composable patterns, the 3.5 idiom cheatsheet, and state-management detail.

## Decide-First Flow

```
Editing a feature .vue?
  → Business logic or fetch in <script setup>?            YES → move to useXxx()
  → Any Tailwind class or inline style?                   YES → use a semantic class (add one if missing)
  → Any raw <button>/<input>/<select>/<textarea>/<dialog>? YES → swap for a shadcn-vue primitive
  → Any hardcoded color/px?                               YES → use a token
```

## Red Flags — stop and fix

- Any Tailwind utility or `style=""` in a feature `.vue`.
- Raw `<button>/<input>/<select>/<textarea>/<dialog>` in feature code.
- `fetch`, `axios`, or business logic inside `<script setup>`.
- Hardcoded `#hex` / `oklch()` / arbitrary `px` in a feature file.
- `any` in props, emits, or a composable's return type.
- A Pinia store holding component-local or single-feature state.

## Rationalizations (do not accept)

| Excuse | Reality |
|---|---|
| "Just one Tailwind class for layout" | One becomes ten. Add a semantic class to the central sheet. |
| "shadcn-vue lacks this, I'll use a raw `<button>`" | Add the primitive to `components/ui/`, then use it. |
| "This logic is small — no composable needed" | Small logic repeated across components is duplication. Extract it. |
| "I'll add the token later" | Hardcoded values drift and multiply. Token now. |
| "Tailwind is faster here" | Speed now = inconsistency debt forever. Use the catalog. |
| "The design doc isn't loaded" | Read the central stylesheet — semantic classes are the contract. |
| "It's only a prototype" | Prototypes become production. Follow the rules from the first line. |

**Violating the letter of these rules is violating the spirit.** There is no "just this once."

## Reference Files (load on demand)

- `semantic-class-catalog.md` — portable baseline semantic-class vocabulary + naming + extension rules.
- `vue3-idioms.md` — composable-first patterns, Vue 3.5 cheatsheet, state-management discipline.
