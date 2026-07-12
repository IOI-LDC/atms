# Backend Handoff Queue — items surfaced by frontend work

> Backend changes the frontend needs but cannot make itself (frontend team does
> not touch the backend). Each item is written as a **ready-to-hand prompt**:
> problem first, the team owns the design decision, and any recommendation is
> marked as **needs verification**. The frontend is already wired to light up
> when each lands — no frontend change required unless noted.
>
> **Status:** proposed, awaiting backend pickup. Created 2026-07-12.

| # | Area | Item | Frontend today | Priority (suggested) |
|---|---|---|---|---|
| A1 | Find & Move | Search should also match `asset_tag` | Placeholder says "name or code" | High |
| A2 | Find & Move | `withCount('childAssets')` on assets index | Components heads-up stays hidden | Medium |
| A3 | Find & Move | "Recent relocations" endpoint (last N) | Shows ≤5 from dashboard feed | Medium |
| A4 | Asset Assembly (Phase 2) | Children listing + atomic cascade move | Cascade seam only; not active | Phase 2 |
| B | Meter readings | Reject a decreasing reading (monotonicity) | Warn + confirm at WO dialog only | High |

---

## A. Find & Move (Logistics location-update view, `/locations2`)

### A1 — Asset search should also match the asset tag

**Problem:** `AssetIndexQuery` (`GET /assets?search=`) matches only `LOWER(name)`
and `LOWER(erp_asset_code)`. Logistics identify assets by the **printed asset
tag** (`asset_tag`, e.g. `L-BBB-CCC-XXXX`), so typing the tag returns nothing.

**Desired:** Include `asset_tag` in the search predicate (case-insensitive LIKE,
same pattern as the existing `name`/`erp_asset_code` clauses).

**Where:** `app/Queries/Assets/AssetIndexQuery.php` (the `search` branch).

**Frontend effect when done:** the combobox finds assets by tag; update the
placeholder from "name or code" to include "tag".

### A2 — Include `child_assets_count` on the assets index

**Problem:** `AssetIndexQuery` does `->with('currentLocation')` but not
`->withCount('childAssets')`. `AssetResource` exposes `child_assets_count` via
`whenCounted('childAssets')`, so it is **absent** from list responses.

**Desired:** Add `->withCount('childAssets')` to the index query (and, if cheap,
the `show` endpoint) so `child_assets_count` is returned.

**Where:** `app/Queries/Assets/AssetIndexQuery.php`.

**Frontend effect when done:** the confirmed "Has N components — move them
separately (Phase 2)" heads-up renders automatically on move cards.

### A3 — "Recent relocations" endpoint (last N, default 10)

**Problem:** The only source of recent moves is `GET /dashboard/kpis` →
`recently_relocated_assets`, which `RecentlyRelocatedAssetsQuery` caps at **5**
within a **90-day** window. The Find & Move "Recently moved" panel wants the
**last 10** regardless of window.

**Desired:** A read-only endpoint returning the most recent relocations
(newest first), default limit 10, using `AssetLocationHistoryResource` (already
carries `asset`, `from_location`, `to_location`, `effective_at`, `reason`).

**Decisions for the team (please confirm):**
- Route shape — `GET /locations/recent-moves?limit=10`, or a filterable
  `GET /asset-location-histories?sort=effective_at:desc&per_page=10`?
  *(Recommend a small dedicated read endpoint — needs verification.)*
- Any window bound, or purely "last N"? *(Recommend last N, no window.)*
- Role visibility — mirror the dashboard (all authenticated). *(Recommend yes.)*

**Frontend effect when done:** point `useRecentMoves` at the new endpoint; the
panel then shows the real last 10.

### A4 — Asset Assembly cascade (Phase 2)

> Conditional on Asset Assembly being in the agreed delivery scope. The frontend
> move flow is already modelled as "primary asset + optional cascade set" so this
> drops in without a redesign.

**Problem:** When a **parent** asset moves, its installed **children** should
move with it. Today there is no way to (a) list a parent's components or
(b) move several assets atomically — `POST /assets/{id}/location` is single-asset,
and there is no children-listing route.

**Desired:**
1. **List a parent's components** with each child's current location and install
   state (`maintenance_sub_status` `installed`/`ready`).
2. **Atomic cascade move** — move the parent plus a caller-selected subset of
   children in one transaction, writing a location-history row **and** an audit
   entry per asset, with shared `reason`/`notes`.

**Decisions for the team (please confirm):**
- Cascade shape — extend `POST /assets/{id}/location` with
  `cascade_child_ids: number[]`, or a dedicated batch endpoint?
  *(Recommend the `cascade_child_ids` extension — needs verification.)*
- Nested assemblies (grandchildren) — in or out of Phase 2 scope?
- Moving a **child alone** — does it detach from the parent, or just relocate?
- Which install states are eligible to cascade?

**Frontend effect when done:** the move sheet's reserved "Components" section
renders the children checklist (all checked by default; uncheck any not
physically co-located; highlight children already at a different location).

---

## B. Meter readings — reject a decreasing value (monotonicity)

**Problem:** `AssetMeterReadingController::store` validates only
`reading_value => ['required','numeric']`, and `RecordMeterReading` has no
monotonicity check. A reading **lower** than the asset's previous reading (same
`usage_reading_type_id`) is accepted from **any** path — the WO record-reading
dialog, Asset Detail, PM, or a direct API call. This corrupts reading-triggered
PM and reliability metrics. The frontend now warns + requires an explicit
acknowledgement at the WO dialog, but that guard is bypassable, so the
authoritative rule must live server-side.

**Desired:** Reject a decreasing reading with `422` — `reading_value` must be
≥ the latest reading for that asset + reading type — while still allowing a
legitimate meter **reset**.

**Decisions for the team (please confirm):**
- **Baseline** — compare against the latest reading by `reading_at`, or the max
  value ever recorded for that asset+type? *(Recommend latest confirmed;
  consider max as stricter — needs verification.)*
- **Legitimate resets** (meter/engine replacement) — provide an explicit
  override so a lower value can be saved intentionally (e.g. an
  `is_reset` / `allow_decrease` flag on the request, or a dedicated reset
  action). The frontend already captures an explicit acknowledgement it can
  send. *(Recommend a request flag — needs verification.)*
- **Equal values** — allow (same reading re-entered)? *(Recommend allow.)*
- **Scope** — apply the same rule to the `update` path, not just `store`?
  *(Recommend yes.)*

**Where:** `app/Http/Controllers/AssetMeterReadingController.php` (`store` +
`update`) and `app/Actions/Assets/RecordMeterReading.php`.

**Frontend effect when done:** `doRecordReading` already surfaces the API error
message, so a clear 422 message displays to the user as-is; no frontend change
required.
