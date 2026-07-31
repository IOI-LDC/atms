# Fix G-03 — Location Picker for Non-Admin Roles (Frontend-only)

**Gap:** `useLocations.ts` and `useAssets.ts` only load locations for
Administrators (calling the Admin-only `/admin/locations`). Manager and
Logistics — who hold `viewAny` + `updateLocation` permission and can reach the
Asset-Movement location-update screen — get an empty picker/filter. Backend
`GET /api/locations` already returns active-only locations for
Admin/Manager/Logistics. **No backend change required.**

**Scope (Option B):** fix both code paths for Manager + Logistics. Keep
`/admin/locations` only for the Admin `ManageLocationsView` CRUD table (needs
inactive rows). **Out of scope:** Phase 2 AM movement-request workflow,
backend changes, all other Phase 1 gaps.

---

## Background — why this is frontend-only

| Layer | Status | Evidence |
|---|---|---|
| `updateLocation` policy | ✅ covers Manager+Logistics | `AssetPolicy.php:46-51` (Admin + Maintenance Manager + Logistics) |
| `GET /api/locations` (active-only) read | ✅ works for Manager+Logistics | `LocationPolicy::viewAny`; proven by `tests/Feature/Locations/ListActiveLocationsTest.php` (Admin/Manager/Logistics → 200; Technician/Requester → 403) |
| Sidebar "Locations" → `asset-location-update` tab | ✅ visible to Logistics | `AppSidebar.vue:79` `visibleTo: isAdminOrManager || isLogistics` |
| Frontend location fetch | ❌ BROKEN | `useLocations.ts:26` only fetches when `auth.isAdmin`; `useAssets.ts:51` calls Admin-only `/admin/locations` |

Current behaviour for a non-Admin: `AssetLocationUpdateView` mounts → calls
`loadLocations()` → fetch skipped → `activeLocations` empty → location filter
bar hidden (`v-if="activeLocations.length > 0"`) **and** `UpdateLocationSheet`
receives an empty `locations` prop → picker empty. Same bug class on the Assets
list filter (`AssetsView.vue:55` gates `loadLocations()` on `isAdminOrManager`,
so Logistics gets no filter there).

---

## Tasks

### 1. `frontend/src/composables/useLocations.ts` — role-conditional `loadLocations()`

Rewrite `loadLocations()` (currently lines 19–40) so every role with `viewAny`
gets data:

- **Admin** → `GET /admin/locations` (returns **all** locations incl. inactive).
  Needed because `ManageLocationsView` (Admin-only CRUD table) reads `locations`
  (all) and the picker reads the `activeLocations` computed subset.
- **Manager OR Logistics** → `GET /api/locations` (returns **active-only**,
  sorted by name). These roles never reach `ManageLocationsView`, so active-only
  is exactly what their picker/filter need.
- **Technician / Requester** → do **not** fetch (they lack `viewAny`; the call
  would 403).
- Replace the stale TODO comment (lines 24–25) documenting the resolved
  role-conditional behaviour.
- Keep the existing error handling (`locationsError`); the 403 branch now only
  applies to unexpected cases. The `activeLocations` computed (line 17) is
  unchanged — it filters `locations` to active, which is a no-op for the
  Manager/Logistics path (already active-only) and correct for Admin.

Guard pattern, mirroring the existing role getters in `auth.store.ts`
(`isAdmin`, `isManager`, `isLogistics`):

```ts
if (auth.isAdmin) {
  const res = await api.get<{ data: Location[] }>('/admin/locations')
  locations.value = res.data ?? []
} else if (auth.isManager || auth.isLogistics) {
  const res = await api.get<{ data: Location[] }>('/api/locations')
  locations.value = res.data ?? []
}
// else: Technician/Requester — no fetch
```

### 2. `frontend/src/composables/useAssets.ts` — switch filter endpoint

`loadLocations()` (lines 47–56) currently calls `/admin/locations` and
client-filters to active. The Assets-list filter only needs active locations:

- Change the endpoint to `GET /api/locations` (active-only, the semantically
  correct read-active endpoint that Manager/Logistics can access).
- The client-side `.filter((l) => l.is_active)` (line 52) becomes redundant
  (endpoint already returns active-only) — remove it for clarity, or keep as
  defensive (match surrounding style).
- Update the doc comment (lines 35, 42–43) to reflect the new endpoint and that
  all three viewAny roles (Admin/Manager/Logistics) are covered.

### 3. `frontend/src/views/assets/AssetsView.vue` — widen the gate

Line 55 gates the fetch on `auth.isAdminOrManager`. Extend to include Logistics:

```ts
if (auth.isAdminOrManager || auth.isLogistics) loadLocations()
```

---

## Constraints / traps

- **Do NOT switch the Admin branch of `useLocations` to `/api/locations`.**
  `ManageLocationsView` (Admin CRUD table) requires **inactive** location rows
  too; `/api/locations` returns active-only and would starve that table.
- **Do NOT make the fetch unconditional.** Technician/Requester lack `viewAny`
  and would receive a 403 (and a misleading "Location list not available"
  banner). The role gate must skip them.
- `/api/locations` sits behind `auth:sanctum` + `EnsureTokenAbilities`; the SPA
  session qualifies — no token changes needed.

---

## Validation

No frontend unit-test framework exists (no vitest / no `.test.ts`/`.spec.ts`).
Validate with:

1. `npm run type-check` (vue-tsc) — must pass.
2. `npm run build` — must pass.
3. Manual role-based verification:
   - **As Logistics:** Locations → Asset Location Update → location filter bar
     appears and picker is populated; submit a location change → success toast +
     asset reloads.
   - **As Maintenance Manager:** same on the Asset-Movement screen **and** the
     Assets list location filter now populates.
   - **As Administrator:** `ManageLocationsView` CRUD table still shows
     **inactive** rows (regression check); AM screen picker still works.
   - **As Technician / Requester:** no location fetch attempted (no 403 banner).
4. Backend unchanged — `php artisan test --compact tests/Feature/Locations/ListActiveLocationsTest.php`
   continues to prove the endpoint serves Manager/Logistics.

---

## Out of scope

- Phase 2 AM movement-request workflow (Requester → Logistics approve → confirm arrival).
- Any backend change (`GET /api/locations`, `LocationPolicy::viewAny`,
  `AssetPolicy::updateLocation` already cover Manager/Logistics).
- All other Phase 1 gaps (G-05, G-06, G-08, G-09, G-10, G-11, G-12, Manager
  admin-area access, dead-code deletion).

## Affected files

- `frontend/src/composables/useLocations.ts`
- `frontend/src/composables/useAssets.ts`
- `frontend/src/views/assets/AssetsView.vue`

## Follow-up after implementation

- Update `docs/PHASE_1_GAP_ANALYSIS.md` §4.3 (G-03 → CLOSED) and the action
  plan / risk register; move G-03 to 🟢 Done in `.kilo/TLD.md`.
