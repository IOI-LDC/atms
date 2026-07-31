# Reports and Current Dashboard Enhancement Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the explicit report dimensions and enhance the current `/dashboard` design with shared Asset and Part Identity presentation.

**Architecture:** Keep the existing report endpoints, queries, dashboard KPIs, authorization boundaries, and current `/dashboard` mosaic design. Centralize the shared asset-dimension key/label rules, update backend and frontend contracts together, then add shared identity components to the current dashboard.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, PostgreSQL 17, Vue 3.5, TypeScript, Vite, Tailwind CSS 4, shadcn-vue.

---

## Execution constraints

- Work from the current `main` worktree unless the user explicitly requests a
  branch or separate worktree.
- Preserve unrelated dirty-tree changes.
- Do not commit, push, deploy, or change production without an explicit user
  instruction.
- Use TDD: write each focused failing test, observe the expected failure, then
  implement the smallest corresponding change.
- Use `laravel-best-practices` for PHP changes and
  `vue-frontend-guardrails` for Vue/TypeScript changes.

### Task 1: Make report dimension contracts explicit

**Files:**

- Create: `backend/app/Queries/Reports/AssetReportDimension.php`
- Modify: `backend/app/Http/Controllers/ReportController.php`
- Modify: `backend/app/Queries/Reports/MtbfReportQuery.php`
- Modify: `backend/app/Queries/Reports/MttrReportQuery.php`
- Modify: `backend/app/Queries/Reports/BadActorReportQuery.php`
- Modify: `backend/tests/Feature/Reports/MtbfReportTest.php`
- Modify: `backend/tests/Feature/Reports/MttrReportTest.php`
- Modify: `backend/tests/Feature/Reports/BadActorReportTest.php`

**Step 1: Write failing contract tests**

Add focused tests asserting:

- `group_by=category` returns `422`;
- Maintenance Category uses `code` as `group_key` and `name` as
  `group_label`;
- two categories with the same display name but different codes remain
  separate;
- null Maintenance Category returns `uncategorised` / `Uncategorised`;
- Size returns canonical `group_key` and O&G `group_label`;
- equivalent `6.75`, `6 3/4`, and `6 3/4"` values group together;
- null Size returns `unspecified` / `Unspecified`;
- Asset Class uses `fa_subclass_code`;
- Asset grouping includes the shared Asset Identity payload.

Run:

```bash
docker exec atms-api php artisan test --compact \
  tests/Feature/Reports/MtbfReportTest.php \
  tests/Feature/Reports/MttrReportTest.php \
  tests/Feature/Reports/BadActorReportTest.php
```

Expected: the new assertions fail because Size is unsupported, category keys
currently use names, and the frontend-era `category` contract is incomplete.

**Step 2: Implement the shared dimension resolver**

Create a small resolver for the common asset dimensions:

```php
final class AssetReportDimension
{
    /**
     * @return array{key: int|string, label: string}
     */
    public function resolve(Asset $asset, string $dimension): array
    {
        return match ($dimension) {
            'asset' => ['key' => $asset->id, 'label' => $asset->name],
            'maintenance_category' => [
                'key' => $asset->maintenanceCategory?->code ?? 'uncategorised',
                'label' => $asset->maintenanceCategory?->name ?? 'Uncategorised',
            ],
            'asset_class' => [
                'key' => $asset->fa_subclass_code ?: 'unclassified',
                'label' => $asset->fa_subclass_code ?: 'Unclassified',
            ],
            'size' => [
                'key' => $asset->size_inches?->canonical() ?? 'unspecified',
                'label' => $asset->size_inches?->format() ?? 'Unspecified',
            ],
            default => throw new InvalidArgumentException(
                "Unsupported asset report dimension [{$dimension}]."
            ),
        };
    }
}
```

Use it in MTBF, MTTR, and Bad Actors. Keep Location and Technician logic inside
their applicable queries. When grouped by Asset, attach the loaded Asset model
for serialization through `AssetIdentityResource`.

Update controller validation to the exact approved lists.

**Step 3: Run the focused tests**

Run the Task 1 test command again.

Expected: all three report test files pass.

### Task 2: Align frontend report dimensions and asset identity

**Files:**

- Modify: `frontend/src/types/index.ts`
- Modify: `frontend/src/lib/reportOptions.ts`
- Modify: `frontend/src/composables/useMtbfReport.ts`
- Modify: `frontend/src/composables/useMttrReport.ts`
- Modify: `frontend/src/composables/useBadActorReport.ts`
- Modify: `frontend/src/views/reports/MtbfReport.vue`
- Modify: `frontend/src/views/reports/MttrReport.vue`
- Modify: `frontend/src/views/reports/BadActorReport.vue`
- Modify: `frontend/src/composables/useReportCatalog.ts`

**Step 1: Tighten the TypeScript contracts**

Replace the legacy group types with:

```ts
export type MtbfGroupBy =
  | 'asset'
  | 'maintenance_category'
  | 'asset_class'
  | 'size'
  | 'location'

export type MttrGroupBy =
  | 'asset'
  | 'maintenance_category'
  | 'asset_class'
  | 'size'
  | 'technician'
```

Give grouped rows an optional `asset: AssetIdentity | null` used only for Asset
grouping.

**Step 2: Update shared option lists**

Replace generic Category options with explicit Maintenance Category and Asset
Class options. Add Size. Keep separate lists for MTBF/Bad Actors and MTTR so
Location and Technician remain report-specific.

**Step 3: Update the three report pages**

- Send only the approved group values.
- Render `<AssetIdentity>` when the applied group is `asset`.
- Render `group_label` for other dimensions.
- Update subtitles, headings, filter labels, empty states, and report-catalogue
  questions to use Maintenance Category, Asset Class, and Size.
- Do not display or search ERP asset codes.

**Step 4: Verify frontend contract parity**

Run:

```bash
cd frontend
npm run type-check
npm run build
```

Expected: both commands exit successfully.

### Task 3: Make Parts Consumption identity- and size-aware

**Files:**

- Modify: `backend/app/Queries/Reports/PartsConsumptionReportQuery.php`
- Modify: `backend/app/Http/Resources/PartsConsumptionReportItemResource.php`
- Modify: `backend/tests/Feature/Reports/PartsConsumptionReportTest.php`
- Modify: `frontend/src/types/index.ts`
- Modify: `frontend/src/views/reports/PartsConsumptionReport.vue`

**Step 1: Write failing backend tests**

Add tests asserting:

- output contains `asset_class`, not `fa_subclass_code`;
- output contains a nested Part Identity with supplier Part Number, Part Size,
  Maintenance Category, unit of measure, and availability snapshot;
- output does not contain `part_code`;
- output contains `asset_size` and `asset_size_inches`;
- identical part/class rows with different Asset Sizes remain separate;
- null Asset Size appears as `Unspecified`;
- cursor pagination remains deterministic with repeated part and class values.

Run:

```bash
docker exec atms-api php artisan test --compact \
  tests/Feature/Reports/PartsConsumptionReportTest.php
```

Expected: the new assertions fail against the current flat ERP-code response.

**Step 2: Update aggregation and resource output**

Join the part Maintenance Category and select the complete Part Identity fields.
Group by:

- part ID and its identity fields;
- Asset Class;
- canonical Asset Size.

Order by part ID, Asset Class, Asset Size, and a deterministic final key.

Format part and asset sizes through the existing `Size` value object. Return the
part using the same shape as `PartIdentityResource`; do not synthesize a
different frontend identity contract.

**Step 3: Update the Parts Consumption page**

- Replace name + ERP code with `<PartIdentity :part="row.part" stacked />`.
- Add explicit Asset Class and Asset Size columns.
- Let Part Identity display Part Number, Part Size, and Part Maintenance
  Category badges.
- Update the row key to include part ID, Asset Class, and canonical Asset Size.

**Step 4: Run focused backend and frontend verification**

Run the Parts Consumption PHPUnit file, then:

```bash
cd frontend
npm run type-check
npm run build
```

Expected: all commands pass.

### Task 4: Enhance the current `/dashboard`

**Files:**

- Modify: `frontend/src/views/DashboardPlaceholderView.vue`
- Modify: `backend/tests/Feature/Dashboard/DashboardTest.php`
- Modify: `backend/tests/Feature/Dashboard/DashboardKpiTest.php`

**Step 1: Write failing dashboard identity tests**

For Pending MRs, Open WOs, Overdue PM, Recently Closed WOs, and Recent
Relocations, assert the embedded asset carries:

- `id`;
- `name`;
- `asset_tag`;
- `serial_number`;
- `size`;
- `size_inches`;
- `maintenance_category`.

Also assert ordinary-role payloads do not expose `erp_asset_code`.

Run:

```bash
docker exec atms-api php artisan test --compact \
  tests/Feature/Dashboard/DashboardTest.php \
  tests/Feature/Dashboard/DashboardKpiTest.php
```

Expected: any remaining incomplete identity or eager-loading path fails.

**Step 2: Update dashboard rendering**

In `DashboardPlaceholderView.vue`, import and use `AssetIdentity` for all five
asset-bearing queues. Preserve the current mosaic layout. Render the MR/WO
number as separate text and the asset identity beneath or beside it:

```vue
<strong>{{ workOrder.number }}</strong>
<AssetIdentity :asset="workOrder.asset" stacked />
```

Do not construct strings such as
`WO-001 — Asset Name — Serial Number — Size`.

**Step 3: Verify the current route and design remain intact**

- Confirm `/dashboard` still routes to `DashboardPlaceholderView.vue`.
- Confirm its mosaic layout, KPI panels, role visibility, queues, and quick
  actions remain intact.

**Step 4: Run dashboard verification**

Run the two dashboard PHPUnit files and the frontend type-check/build commands.

Expected: all pass.

### Task 5: Complete documentation lifecycle and final verification

**Files:**

- Modify after implementation: `docs/REQUIREMENTS.md`
- Modify after implementation: `docs/IMPLEMENTATION_HISTORY.md`
- Modify after implementation: `docs/README.md`
- Modify if contracts changed: `docs/API.md`
- Modify if routes changed: `docs/FRONTEND.md`

**Step 1: Update active documentation**

- Remove completed R-007 from `REQUIREMENTS.md`.
- Record its verified outcome in `IMPLEMENTATION_HISTORY.md`.
- Record the identity enhancement to the current `/dashboard` design and its
  unchanged KPI scope.
- Update the current snapshot and API/frontend summaries.

**Step 2: Format changed code**

Run targeted frontend formatting:

```bash
cd frontend
npm run format
```

Run Pint on the changed PHP files without formatting unrelated user changes.

**Step 3: Run the focused regression set**

```bash
docker exec atms-api php artisan test --compact \
  tests/Feature/Reports/MtbfReportTest.php \
  tests/Feature/Reports/MttrReportTest.php \
  tests/Feature/Reports/BadActorReportTest.php \
  tests/Feature/Reports/PartsConsumptionReportTest.php \
  tests/Feature/Dashboard/DashboardTest.php \
  tests/Feature/Dashboard/DashboardKpiTest.php
```

Then:

```bash
cd frontend
npm run type-check
npm run build
```

Expected: all focused backend tests, TypeScript checks, and the production build
pass.

**Step 4: Browser verification**

Verify as Administrator, Manager, Technician, Requester, and Logistics where
applicable:

- `/dashboard` loads the existing mosaic dashboard design;
- role-hidden queues remain hidden;
- all visible asset identities contain the available badges without
  concatenation;
- MTBF, MTTR, and Bad Actors accept each approved dimension;
- `category` is absent from controls and rejected by the API;
- Parts Consumption shows Part Identity, Asset Class, and Asset Size;
- narrow layouts wrap badges without clipping.

**Step 5: Final integrity check**

Run:

```bash
git diff --check
git status --short
```

Review only the files in this plan. Do not stage, commit, push, or deploy unless
the user explicitly requests it.
