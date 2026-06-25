# Change: Dedicated Locations Sidebar Item

**Date:** 2026-06-25
**Scope:** Phase 1 — ATMS Core (Operational Maintenance)
**Status:** Specified (backend dependency resolved 2026-06-25; frontend not yet implemented)

## Motivation

Currently, asset location updates in Phase 1 require going through the Asset
Detail → Edit Asset path (`PATCH /api/assets/{asset}`), which is only available
to Admin and Manager. The Logistics role — whose primary Phase 1 function is
updating asset physical locations — has no dedicated workspace. The
`UpdateLocationSheet` component is planned but not built, and there is no
sidebar link for it.

The `POST /api/assets/{asset}/location` endpoint already exists and is
authorized for Admin, Manager, and Logistics — but the frontend has no dedicated
screen to use it.

## Summary of Change

Add a new **"Locations" sidebar item** (tabbed group, positioned between
"Parts Management" and "Admin") with two tabs:

| Tab | Visible To | Purpose |
|---|---|---|
| **Asset Location Update** | Admin, Manager, Logistics | Search assets, view current location, open `UpdateLocationSheet` to call `POST /api/assets/{asset}/location` |
| **Manage Locations** | Admin only | CRUD for location definitions (name, type, code, parent, active status) |

This supersedes the now-stale `LocationsView.vue` stub at `/settings/locations`.

## Backend Dependency

A new read-only endpoint is required for the frontend:

| Method | Endpoint | Auth | Returns |
|---|---|---|---|
| `GET` | `/api/locations` | Admin, Manager, Logistics | Active locations only (`is_active = true`) |

This is distinct from the existing Admin-only `GET /api/admin/locations` which
returns all locations regardless of active status. The new endpoint enables the
location picker dropdown in `UpdateLocationSheet` for Manager and Logistics
roles without granting them admin privileges.

> ✅ **Resolved 2026-06-25.** This endpoint has been implemented. See
> [Backend Implementation](#backend-implementation) below.

## File Change Inventory

| File | Change |
|---|---|
| `docs/atms/02-design/NAVIGATION.md` | Added §6 "Locations" sidebar item. Renumbered Admin to §7, Settings to §8. Updated Role Visibility Summary. |
| `docs/atms/02-design/SCREEN_INVENTORY.md` | Added §6 "Locations" with two tabs. Renumbered Admin to §7, Settings to §8. Updated Role Visibility Summary. Updated "Screens Without Sidebar Entries" table. |
| `docs/atms/04-frontend/ROUTES.md` | Added `/locations` route with `?tab=asset-location-update` and `?tab=manage-locations`. |
| `docs/atms/04-frontend/COMPONENTS.md` | Moved `UpdateLocationSheet` from Assets domain to new "Locations" domain. Added `LocationList` and `LocationForm` components. Removed `UpdateLocationSheet` from Assets domain. |
| `docs/atms/04-frontend/FRONTEND_ARCHITECTURE.md` | Added `/locations` route and `features/locations/` directory to suggested structure. |
| `docs/atms/04-frontend/FORM_REQUIREMENTS.md` | Expanded "Asset Location Update Form" section with full field spec, submission flow, and validation rules. Added "Location Create / Edit Form" section. |
| `docs/atms/04-technical/BACKEND_API_REFERENCE.md` | Fixed Technician auth mismatch (was incorrectly listed as authorized for `POST /api/assets/{asset}/location`). Added `GET /api/locations` endpoint specification. |
| `docs/atms/04-technical/BACKEND_API_HANDOFF.md` | Fixed Technician auth mismatch in endpoint summary. Updated §6.3 "Asset location update" with dedicated Locations screen flow and manage-locations flow. Added Locations section to endpoint quick reference. Added `GET /api/locations` to quick reference. |

## Auth Correctness Fix

A latent documentation bug was fixed: `POST /api/assets/{asset}/location` was
documented as available to `Admin/Mgr/Tech/Logistics` but the authorizing policy
(`AssetPolicy::updateLocation`, lines 46-51) only permits Admin, Manager,
and Logistics. The RBAC.md document was already correct. Two files were fixed:
- `BACKEND_API_REFERENCE.md` line 542: removed "Technician"
- `BACKEND_API_HANDOFF.md` line 1028 (original): removed "Tech"

## Backend Implementation

**Implemented:** 2026-06-25

| File | Change |
|---|---|
| `app/Policies/LocationPolicy.php` | Added `viewAny()` method — authorizes Admin, Manager, Logistics |
| `app/Http/Controllers/LocationController.php` | New controller with `index()` — gates on `viewAny`, returns active locations only (`is_active = true`), sorted by name |
| `routes/api.php` | Registered `GET /api/locations` → `LocationController@index` |
| `tests/Feature/Locations/ListActiveLocationsTest.php` | Feature tests: role authorization (Admin/Manager/Logistics → 200, Technician/Requester → 403, Guest → 401), active-only filtering, name sorting, response shape |

## Frontend Implementation Notes

Components to build (all new):
- `/src/views/locations/LocationsView.vue` — Main page with two tabs
- `/src/views/locations/AssetLocationUpdateView.vue` — Tab 1: asset list + UpdateLocationSheet
- `/src/views/locations/ManageLocationsView.vue` — Tab 2: location CRUD table + forms
- `/src/components/locations/UpdateLocationSheet.vue` — Side sheet for asset location update
- `/src/components/locations/LocationList.vue` — Data table for location definitions
- `/src/components/locations/LocationForm.vue` — Side sheet for create/edit location

Composable to build:
- `/src/composables/useLocations.ts` — `GET /api/locations` query, `POST/PATCH` mutations

Sidebar updates:
- `AppSidebar.vue`: Add "Locations" nav item with `MapPin` icon from `lucide-vue`,
  visible to Admin, Manager, Logistics. Positioned between Parts Management and Admin.

Router updates:
- Add `/locations` route with `asset-location-update` and `manage-locations` tabs

## Test Locations Already Seeded

Eight test locations were seeded on 2026-06-25 into the `locations` table:

| ID | Name | Type | Code |
|---|---|---|---|
| 1 | Workshop | workshop | WS |
| 2 | Main Yard | yard | MY |
| 3 | Workshop Yard | workshop_yard | WSY |
| 4 | Well X | well_site | WX |
| 5 | Well Y | well_site | WY |
| 6 | Rig A | rig | RA |
| 7 | Rig B | rig | RB |
| 8 | Rig C | rig | RC |

Seeded via `LocationSeeder` (registered in `DatabaseSeeder`). All active.
