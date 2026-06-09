# Frontend Design System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the ATMS Vue frontend foundation so every page uses the approved company design system and confirms all user-initiated persistent changes.

**Architecture:** Create a Vue 3 application with a tokenized global CSS layer, shadcn-vue primitives, semantic application classes, and shared page/form/table/state components. Route all writes through a reusable confirmation workflow while keeping backend authorization authoritative.

**Tech Stack:** Vue 3, TypeScript, Vite, Tailwind CSS, shadcn-vue, Vue Router, TanStack Query, Pinia, Vitest, Vue Test Utils

---

### Task 1: Scaffold and Verify the Frontend Application

**Files:**
- Create: `frontend/package.json`
- Create: `frontend/vite.config.ts`
- Create: `frontend/src/main.ts`
- Create: `frontend/src/App.vue`
- Create: `frontend/src/app/router.ts`
- Modify: `compose.yaml`
- Test: `frontend/src/App.test.ts`

**Steps:**

1. Scaffold Vue 3, Vite, and TypeScript under `frontend/`.
2. Add Vue Router, TanStack Query, Pinia, Vitest, and Vue Test Utils.
3. Write a failing application-mount test.
4. Implement the minimal app shell and router.
5. Run `npm test -- --run` from `frontend/`; expect all tests to pass.
6. Add the frontend service to the development Compose topology.
7. Run `docker compose config`; expect a valid configuration.
8. Commit with `feat(frontend): scaffold Vue application`.

### Task 2: Implement Tokens and Semantic CSS

**Files:**
- Create: `frontend/src/assets/main.css`
- Create: `frontend/src/styles/design-tokens.test.ts`
- Modify: `frontend/src/main.ts`

**Steps:**

1. Write a failing test that asserts required company and semantic tokens exist.
2. Define the approved light-theme tokens, spacing, type, radius, elevation, and
   focus variables in `:root`.
3. Add semantic classes for the app shell, page headers, cards, KPI grids,
   tables, filters, forms, sheets, and UI states.
4. Add responsive rules for navigation, KPI grids, forms, and tables.
5. Run the token test and frontend test suite; expect all tests to pass.
6. Commit with `feat(frontend): add ATMS design tokens and semantic styles`.

### Task 3: Install and Wrap Shared UI Primitives

**Files:**
- Create: `frontend/src/components/ui/`
- Create: `frontend/src/components/shared/PageHeader.vue`
- Create: `frontend/src/components/shared/StatusBadge.vue`
- Create: `frontend/src/components/shared/EmptyState.vue`
- Create: `frontend/src/components/shared/LoadingState.vue`
- Create: `frontend/src/components/shared/ErrorState.vue`
- Test: `frontend/src/components/shared/shared-components.test.ts`

**Steps:**

1. Write failing render and accessibility tests for the shared components.
2. Install the required shadcn-vue primitives.
3. Implement shared components using semantic classes only.
4. Verify icon-only controls require accessible labels.
5. Run component tests and the full frontend suite; expect all tests to pass.
6. Commit with `feat(frontend): add shared UI primitives`.

### Task 4: Build Standard Table and Form Containers

**Files:**
- Create: `frontend/src/components/shared/DataTable.vue`
- Create: `frontend/src/components/shared/FilterBar.vue`
- Create: `frontend/src/components/shared/FormSheet.vue`
- Create: `frontend/src/components/shared/ConfirmChangeDialog.vue`
- Test: `frontend/src/components/shared/data-entry-patterns.test.ts`

**Steps:**

1. Write failing tests for loading, empty, pagination, form-sheet, and
   confirmation-dialog behavior.
2. Implement the dense table and filter pattern.
3. Implement side-sheet create/edit form containment.
4. Implement a generic confirmation dialog with summary, exact action label,
   consequence text, pending state, and cancellation.
5. Run focused and full frontend tests; expect all tests to pass.
6. Commit with `feat(frontend): add standard table and form patterns`.

### Task 5: Centralize Persistent-Change Confirmation

**Files:**
- Create: `frontend/src/composables/useConfirmedMutation.ts`
- Create: `frontend/src/types/confirmed-mutation.ts`
- Test: `frontend/src/composables/useConfirmedMutation.test.ts`

**Steps:**

1. Write failing tests for validation-before-confirmation, user cancellation,
   duplicate-submit prevention, successful refresh, and preserved state after
   failure.
2. Implement a typed wrapper around TanStack Query mutations.
3. Require callers to provide a plain-language summary and exact confirm label.
4. Expose validation and server errors without clearing form state.
5. Run focused and full frontend tests; expect all tests to pass.
6. Commit with `feat(frontend): enforce confirmed persistent changes`.

### Task 6: Add Static Design-System Enforcement

**Files:**
- Create: `frontend/eslint.config.js`
- Create: `frontend/scripts/check-feature-ui.mjs`
- Modify: `frontend/package.json`
- Test: `frontend/scripts/check-feature-ui.test.mjs`

**Steps:**

1. Write failing fixture tests for raw interactive elements and Tailwind utility
   classes in feature Vue files.
2. Implement checks that exclude `src/components/ui/` but scan feature,
   layout, and page files.
3. Add the check to `npm run lint` and CI-facing `npm run verify`.
4. Run `npm run verify`; expect lint, policy checks, and tests to pass.
5. Commit with `chore(frontend): enforce UI design-system rules`.

### Task 7: Verify the Foundation in Representative Pages

**Files:**
- Create: `frontend/src/features/dashboard/DashboardPage.vue`
- Create: `frontend/src/features/assets/AssetRegistryPage.vue`
- Create: `frontend/src/features/admin/ProfileSettingsSheet.vue`
- Test: `frontend/src/features/foundation-pages.test.ts`

**Steps:**

1. Write failing tests for a read-only dashboard, dense asset table, and
   confirmed profile edit.
2. Implement representative pages using only shared components and semantic
   classes.
3. Verify role-adaptive actions and backend-error handling.
4. Run `npm run verify`; expect all checks to pass.
5. Run the app and inspect desktop and mobile layouts in the in-app browser.
6. Commit with `feat(frontend): validate design system on foundation pages`.

### Task 8: Update Frontend Delivery Documentation

**Files:**
- Modify: `docs/04-frontend/FRONTEND_ARCHITECTURE.md`
- Modify: `docs/04-frontend/COMPONENTS.md`
- Modify: `docs/06-prompts/CODEX_FRONTEND_PROMPTS.md`

**Steps:**

1. Record the implemented folder structure and shared APIs.
2. Add the design-system and confirmed-mutation requirements to frontend
   implementation prompts.
3. Run documentation link and formatting checks available in the repository.
4. Commit with `docs: record frontend design-system implementation`.
