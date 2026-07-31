# Maintenance Category Normalization Design

## Goal

Normalize the three known Maintenance Category code defects, preserve every
Asset and Part association, and prevent malformed codes from being recreated by
imports or the administration UI.

## Data cleanup

The cleanup ships as a Laravel data migration so every environment, including
production, applies it through the normal deployment migration step.

- Merge `MWD__APS` into `MWD_APS`. Repoint Assets and Parts to the canonical
  category before deleting the duplicate row.
- Rename `MWD__VERTEX` to `MWD_VERTEX`, keeping the visible name
  `MWD / VERTEX`.
- Rename `SUB_FLOW__MWD` to `MWD_SUB_FLOW` and change the visible name to
  `MWD / SUB FLOW`.

The migration runs the updates transactionally. If a canonical row already
exists in an environment, dependent Assets and Parts are repointed to that row
and the legacy duplicate is removed. The duplicate merge is intentionally
irreversible because the original association provenance cannot be reconstructed
after consolidation.

## Recurrence prevention

`MaintenanceCategory::codeFor()` becomes the single normalization path. It
uppercases a name, replaces every run of non-alphanumeric characters with one
underscore, and trims leading or trailing underscores.

The Asset import, Part import, and Admin category creation all use this method.
This removes the current divergence in which the Asset import can leave double
underscores while the Part import already collapses separator runs.

## Frontend impact

The Vue frontend contains no hardcoded references to the malformed codes. It
loads Maintenance Categories from the API, so the migrated database values will
appear automatically in lists, dropdowns, filters, Assets, and Parts. No
frontend source change is required unless verification exposes a cached or
hardcoded value.

## Verification

Regression coverage will prove:

- category names containing adjacent separators produce one canonical code;
- the duplicate APS categories merge without losing Asset or Part assignments;
- the VERTEX and SUB FLOW codes and names are corrected;
- repeated execution does not create duplicates or lose associations;
- the production deployment path runs `php artisan migrate --force`;
- targeted PHPUnit tests, Pint, and the frontend production build pass;
- the current database contains only the canonical rows after migration.
