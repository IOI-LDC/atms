# Asset status vocabulary — true record (corrected 2026-08-15)

**Status:** ✅ Record corrected with the user on 2026-08-15. **No design has
ever been agreed.** Nothing is implemented. Earlier drafts of this file and the
matching tracker entries contained false or unverifiable provenance and are
superseded by this record.

## 1. True record of LDC's vocabulary requests

LDC sent multiple separate requests to add or rename values in the asset
status vocabulary. LDC could not explain the workflow intent behind any of
them. The requested vocabulary, confirmed by the user on 2026-08-15:

| Value | Kind |
|---|---|
| At the Rig | new |
| Need Assembly | new |
| Missing Parts | new |
| Need Inspection | rename of "Under Inspection" |
| Need Maintenance | rename of "Under Maintenance" |
| LIH | requested — home not yet decided |
| DBR | requested — home not yet decided |
| Scrapped | **replaces Disposed — final.** Disposed is not used. |

Rename history that actually happened in code: `inactive` → display-only
"Retired" (2026-08-02) → `scraped` at DB level (2026-08-04). A further
`retired` rename was proposed and abandoned.

Inspections were first raised by LDC in the week of 2026-08-07. **User
decision:** excluded from Phase 1; inspection management moved to a dedicated
**Phase 1.5** with its own estimate (see `docs/FUTURE_SCOPE.md` and
`.kilo/TLD.md` P2-011).

## 2. What was wrong in earlier drafts (do not trust)

- Earlier drafts claimed a design was "AGREED" on 2026-08-14 and then
  "reopened" because the user disliked a new `disposition_status` field
  duplicating `maintenance_sub_status`. **Both claims are false.** No design
  was ever agreed, and the recorded reopen reason is not accurate.
- Earlier drafts claimed the user "agreed to drop Need Inspection explicitly".
  **False — never agreed.**
- Earlier drafts attributed LIH / DBR / Scrapped / Disposed to internal or
  ERP vocabulary rather than to LDC requests. **False — LDC requested them.**
- The 2026-07-31 recorded decision that `maintenance_status = withdrawn` and
  all `maintenance_sub_status` values are "ERP-owned" and "must never be
  surfaced, counted, or reported" is **wrong**. LIH / DBR / Scrapped must be
  visible, filterable, and reportable. The current code still implements the
  old decision (R-10B and R-11 removed from the report catalogue) — a known
  discrepancy to be resolved when the vocabulary work is designed.

## 3. Current code state (facts, verified 2026-08-15)

- `operational_status` (6 values): `ready_for_field`, `under_maintenance`,
  `down`, `scraped`, `under_inspection`, `lih`. Written automatically at
  MR approval, WO start/close/cancel; also hand-set. Consumed by the
  availability KPI, reports and CSV exports. A WO close never reverts a
  `scraped` asset.
- `maintenance_status`: `enrolled` / `withdrawn`, Admin/Manager hand-set.
  `withdrawn` gates MR create (422), WO assignment (409), MR approval (409)
  and PM evaluation.
- `maintenance_sub_status` (7 values): `installed`, `ready`, `lih`, `dbr`,
  `disposed`, `scrapped`, `other`. Admin/Manager hand-set only; no workflow
  writes it. All 400 assets currently NULL. Note: `disposed` is dropped from
  the LDC vocabulary (Scrapped replaces it), so this enum needs trimming in
  the eventual design.
- All MR/WO data is test data and will be wiped at handover; assets reset to
  `ready_for_field`.

## 4. Proposals — never accepted (reference only)

The following designs were drafted by AI sessions. **None was ever agreed to
by the user.** Kept only so future sessions do not re-derive them.

- **2026-08-07 proposal:** 4-value operational axis (`ready_for_field`,
  `under_maintenance`, `down`, `retired`), a new LDC-owned `disposition_status`
  hand-set attribute backed by `master_data_items`, a "one resolved Status"
  rule, WO-close disposition capture, P2-010 un-deferred. Seed list dropped
  the Need-* labels.
- **2026-08-14 proposal:** 3-value operational axis, `scraped` moved to
  `maintenance_status = withdrawn` + `maintenance_sub_status = scrapped` +
  `is_active = false`, `under_inspection` removed pending Phase 1.5,
  `disposition_status` seed of 5 labels (`at_the_rig`, `missing_parts`,
  `need_maintenance`, `need_inspection`, `need_assembly`), P2-010 re-deferred.

## 5. Open decisions

1. Where LIH and DBR live (user: "not sure where to put those").
2. The overall vocabulary design — no proposal has been accepted; this record
   is the agreed starting point for a new design.
3. Resolving the code discrepancy: surfacing LIH / DBR / Scrapped in reports,
   dashboards and exports (R-10B / R-11 reversal).
