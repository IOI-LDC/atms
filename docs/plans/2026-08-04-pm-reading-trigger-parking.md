# PM Reading-Trigger Parking Plan (Date-Only PM Until Job Management Feeds Meter Readings)

> **Status:** APPROVED 2026-08-04 — implementation in progress.

**Goal:** Park (disable without deleting) meter-reading-based PM triggers in the PM Rules UI so preventive maintenance runs on calendar dates only, until a future LDC Job Management system feeds real asset usage (operating hours, kilometers, depth, …) into ATMS.

---

## 1. Problem statement

Reading-based PM triggers are **unreachable through the UI** today. Two verified gaps compound:

1. **No place outside a Work Order to record readings.** The only UI entry point for meter readings is the Work Order detail page ("Record reading…"). The Asset detail "Usage Readings" card is read-only, and the corrective-MR creation form never sends the optional `meter_reading` payload the backend supports. An asset that never had a WO can never accumulate a reading.
2. **No way to verify a recorded reading.** Verification is a deliberate second step (`POST /assets/{asset}/meter-readings/{reading}/confirm`, role-gated to Admin / Maintenance Manager / Technician). The endpoint, action, and tests exist — but **no frontend component ever calls it**, and there is no auto-confirmation anywhere in the backend (including WO close).

Consequence: `PmDueCalculator` counts only **confirmed** readings (`whereNotNull('confirmed_at')`), so the reading dimension of `reading` / `date_or_reading` rules can never fire. Such rules effectively behave as date-only rules anyway, while presenting a misleading "Usage" option to admins.

### Evidence (verified 2026-08-04, local docker DB)

- Asset `M40-800-0051` (id 293, enrolled, category MOTOR) has active category-origin assignments for rules "L1 Maintenance Motors" (90 days / 1000) and "L2 Maintenance Motors" (180 days / 2000), both `date_or_reading`, baseline 2026-08-01 — no PM was triggered, correctly.
- Zero meter readings exist for that asset; all 8 readings system-wide have `confirmed_at = null`.
- `RecordMeterReading`: "Just records it, does not confirm it. Confirmation is separate."
- `ConfirmMeterReading` is referenced only by `AssetMeterReadingController::confirm` — no other caller, no auto-confirm.
- Grep of `frontend/src` confirms no call to the `/confirm` endpoint anywhere.
- On production the user observed two readings stuck "Unverified" — same gap.

---

## 2. Decision (user-approved direction)

- **Park** the meter-reading triggers: hide `reading` and `date_or_reading` from the PM rule form so new rules can only be created as **date-based**.
- **Delete nothing**: backend validation, `ConfirmMeterReading`, calculator reading logic, `pm_rules.interval_reading` / `usage_reading_type_id` columns, and existing rules' data all stay intact.
- **Existing rules untouched**: current `date_or_reading` rules (test data) keep working on their date side; the reading side remains inert without confirmed readings. No data migration.
- **Long term:** re-enable when the LDC Job Management system tracks asset usage per job and feeds meter readings into ATMS — at which point the verify path must also be built (see §5).

## 3. Change scope

### Task 1: Frontend — hide reading trigger options

**File:** `frontend/src/components/pm-rules/PmRuleForm.vue`

`TRIGGER_OPTIONS` (~line 51) is the single control point — both the single-template select and the multi-level batch rows render from it, and every default is already `'date'`:

```ts
// Reading-based triggers are parked until meter readings are fed in from the
// Job Management system (and a verify path exists). Restore the two options
// below to re-enable; the backend contract is unchanged.
const TRIGGER_OPTIONS = [
  { value: 'date', label: 'Calendar (date-based)' },
]
```

Nothing else changes. The trigger type is only settable at creation (the update endpoint does not accept `trigger_type`), so edit mode is unaffected.

### Task 2: Verification

- `npm run type-check` (in `frontend/`) — GREEN.
- Manual: PM Rules → New Rule → trigger dropdown shows only "Calendar (date-based)".
- No backend change → no backend tests required; run `php artisan test --compact --filter=PmRule` as a smoke check that the API contract is untouched.

### Task 3: Record the decision

- `.kilo/STATE.md` and `.kilo/TLD.md` — record the parking decision and its re-enablement condition (project convention).

**Commit:** `feat(pm-rules): park reading-based triggers — date-only PM until Job Management feed`.

## 4. Explicitly out of scope

- Flipping existing rules' `trigger_type` in the database (test data; unnecessary).
- Backend enforcement (API still accepts reading trigger types — acceptable for a temporary park).
- Building the verify/confirm UI and asset-level reading entry — tracked as future work (§5).

## 5. Future work (prerequisites to re-enable)

1. **Meter-reading verify UI** — wire the existing confirm endpoint (WO readings table and/or Asset detail Usage Readings card, role-gated Admin/Manager/Technician).
2. **Reading entry outside WOs** — record usage directly on the Asset detail page.
3. **Job Management integration** — external system feeds per-job asset usage into ATMS.
4. **LDC open questions on WO-close reset policy** (current behavior stays until answered):
   - Cascade: closing an L3 PM currently resets L1+L2 baselines (cumulative model) — confirm LDC wants this.
   - Corrective WOs currently do **not** reset PM baselines — only preventive WO closure does. Confirm whether serviced-correctively should also count.

## 6. Re-enablement procedure (when Job Management feed lands)

Restore the two `TRIGGER_OPTIONS` entries in `PmRuleForm.vue`:

```ts
{ value: 'reading', label: 'Usage (reading-based)' },
{ value: 'date_or_reading', label: 'Whichever comes first' },
```

No backend change needed — stored `interval_reading` / `usage_reading_type_id` and assignment reading baselines were never deleted.
