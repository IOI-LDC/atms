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
| A2 | Find & Move / Asset Assembly | Return `child_assets_count` when component awareness is enabled | Components heads-up stays hidden | Phase 2 |
| A3 | Find & Move | "Recent relocations" endpoint (last N) | Shows ≤5 from dashboard feed | Medium |
| A4 | Asset Assembly (Phase 2) | Children listing + atomic cascade move | Cascade seam only; not active | Phase 2 |
| B | Meter readings | Decide whether to support an audited meter-reset workflow | Decreasing confirmed readings are already rejected | Product decision |

---

## A. Find & Move (Logistics location-update view, `/locations2`)

### A1 — Asset search should also match the asset tag

**Problem:** `AssetIndexQuery` (`GET /assets?search=`) matches only `LOWER(name)`
and `LOWER(erp_asset_code)`. Logistics identify assets by the **printed asset
tag** (`asset_tag`, e.g. `L-BBB-CCC-XXXX`), so typing the tag returns nothing.

**Desired:** Include `asset_tag` in the search predicate (case-insensitive LIKE,
same pattern as the existing `name`/`erp_asset_code` clauses).

**Where:** `app/Queries/Assets/AssetIndexQuery.php` (the `search` branch).

**Backend verification:** Add focused feature coverage for tag matches while
preserving existing name/code search, role scoping, and cursor pagination.

**Frontend effect when done:** the combobox finds assets by tag; update the
placeholder from "name or code" to include "tag".

### A2 — Component count on the assets index (Phase 2)

> **Deferred to Phase 2.** Asset Assembly and component-aware movement are outside
> Phase 1. There is no Phase 1 backend pickup for this item.

**Problem:** `AssetIndexQuery` does `->with('currentLocation')` but not
`->withCount('childAssets')`. `AssetResource` exposes `child_assets_count` via
`whenCounted('childAssets')`, so it is **absent** from list responses.

**Phase 2 desired outcome:** Return `child_assets_count` when the consuming flow
needs component awareness. The Phase 2 design should decide whether this belongs
on every assets-index response or is opt-in, to avoid adding an unnecessary count
subquery to all Phase 1 asset searches.

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

**Required API safeguards:**

- Validate `limit` as an integer with a conservative upper bound (recommended
  default 10, maximum 50).
- Order deterministically by `effective_at DESC`, then `id DESC`, so equal
  timestamps do not reorder between requests.
- Verify the production query plan and add an index compatible with the final
  filter/order pattern if data volume warrants it.
- Cover authentication/authorization, default and maximum limits, newest-first
  ordering, and equal-timestamp ordering in feature tests.

**Frontend effect when done:** point `useRecentMoves` at the new endpoint; the
panel then shows the real last 10.

### A4 — Asset Assembly cascade (Phase 2)

> **Deferred to Phase 2; no Phase 1 backend pickup.** The frontend move flow is
> already modelled as "primary asset + optional cascade set" so this can be
> activated when the Phase 2 assembly contract is agreed.

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

## B. Meter readings — decide whether to support legitimate meter resets

**Current backend behavior (verified):** Recording creates an **unconfirmed**
reading. `ConfirmMeterReading` compares it with the latest confirmed reading for
the same asset and usage-reading type. A lower value or an earlier reading date
is rejected with `409`; equal values are allowed. Confirmed readings cannot be
edited, and reading-triggered PM calculations use confirmed readings only.

Therefore, decreasing readings do **not** currently create an authoritative PM
or reliability-data corruption gap. Server-side monotonicity already exists at
the correct transition: confirmation.

**Open product decision:** Must ATMS support a legitimate reset after a meter,
engine, or counter replacement?

- **If no:** close this item; the existing confirmation rule is sufficient.
- **If yes:** design a dedicated, privileged, audited meter-reset action. Do not
  expose a general `allow_decrease` flag on the ordinary record/update request,
  because that would turn the authoritative invariant into a caller-controlled
  bypass.

**Reset workflow requirements if approved:**

- Explicit authorization for the role permitted to approve a reset.
- Required reset reason and effective date, with optional replacement reference.
- Atomic creation/confirmation of the new baseline and a complete audit trail of
  the previous confirmed value, new value, actor, reason, and timestamp.
- Subsequent confirmations compare against the approved reset baseline.
- Feature tests for unauthorized reset attempts, required reason, audit output,
  transactional rollback, and post-reset monotonicity.

**Likely backend touchpoints:** a dedicated Form Request, controller/action,
authorization rule, route, audit event, and focused feature tests. Preserve the
existing `ConfirmMeterReading` rejection behavior for ordinary confirmations.

**Frontend effect if resets are approved:** a frontend change will be required
to invoke the privileged reset workflow and capture its mandatory reason. The
current WO-dialog acknowledgement is local UI state and is not a reset request.
