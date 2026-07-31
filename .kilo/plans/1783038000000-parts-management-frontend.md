# Parts Management — Frontend Build

> **Audience:** Frontend team.
> **Philosophy:** Architecture and outcomes only. The team has full freedom on
> component decomposition, file organisation, naming, and styling — as long as
> the contracts (the backend API, the cleanup requirement) are honoured and the
> result is consistent with the rest of the app.

---

## 1. Problem Statement

The **Parts Management** page (`/parts`) and **Part Detail** page (`/parts/:partId`)
are placeholder stubs that render "Parts list coming soon" / "Part detail coming soon".
The backend already fully supports parts — list, detail, update, attachments, and
Work-Order consumption — and the `parts` table is now seeded with 55 realistic O&G
drilling-maintenance parts. None of this is visible in the UI yet.

At the same time, the **Work Order "Add part" picker** carries a hardcoded mock
catalogue fallback (`MOCK_PARTS`) that only existed because `GET /parts` used to return
an empty list. With the table seeded, that fallback is redundant dead code that
diverges from the real catalogue.

**Goal:** Make Parts Management a functional, read-only experience backed by the live
`GET /parts` API, and remove the obsolete mock so every parts surface reads from one
source of truth. This delivers a working experience now for demo/UAT while the real ERP
Parts sync is pending; **when real ERP data arrives, zero frontend changes are
required** — the same endpoints just return different rows.

---

## 2. Outcomes (what "done" looks like)

1. **Parts list** — a user lands on `/parts` and sees the catalogue (currently 55 parts)
   with search, category filtering, and a way to drill into a single part.
2. **Part detail** — a user opens a part and sees its full reference data, with an
   attachments section (view + upload + delete, role-gated per the existing attachment
   policy).
3. **Single source of truth** — the WO "Add part" picker and the Parts page both read
   the live `GET /parts` endpoint. The `MOCK_PARTS` fallback and its supporting mock
   infrastructure are gone.
4. **Role-appropriate visibility** — non-Admin/Manager roles see only active parts;
   Admin/Manager see the inactive ones too, with a clear status indicator.
5. **No regressions** — the WO "Parts used" section and Add Part dialog keep working,
   now against real data.

---

## 3. Backend Contract (must respect — this is the hard constraint)

All endpoints are implemented, auth-gated, and seeded. These are read-mostly for the UI.

| Method | Path | Auth | Notes |
|---|---|---|---|
| `GET` | `/api/parts` | Any authenticated | cursor-paginated; `?search=` (case-insensitive on name + erp_part_code), `?sort=` (`name` or `erp_part_code`, `:asc`/`:desc`), `?per_page=` (max 100). Non-Admin/Manager only see `is_active=true`. |
| `GET` | `/api/parts/{part}` | Any authenticated | single part; inactive parts visible to Admin/Manager only |
| `GET` | `/api/parts/{part}/attachments` | Any authenticated | `{ data: Attachment[] }` |
| `POST` | `/api/parts/{part}/attachments` | Any authenticated | multipart upload |
| `DELETE` | `/api/attachments/{attachment}` | per AttachmentPolicy | soft delete |
| `PATCH` | `/api/parts/{part}` | Admin/Manager | (not required for this build — detail can stay read-only) |

**`Part` resource fields:** `id, erp_part_code, name, description, unit_of_measure,
category, is_active, created_at` — plus `erp_status` + `erp_last_synced_at`
(Admin/Manager only) and `erp_raw_data` (Admin only, currently null on seed rows).

**Seeded data:** 55 parts across 11 categories — Mud Motor, MWD/LWD, Downhole Tools,
Drill Collars, Wireline, Completion, Hydraulics, Filters & Fluids, Bearings & Seals,
Electrical, Consumables. Use this as representative test data, not a fixed list.

---

## 4. Files (context, not prescriptions)

**Stubs to be replaced:**
- `frontend/src/views/parts/PartsView.vue`
- `frontend/src/views/parts/PartDetailView.vue`

**Mock to remove (dead after seeding):**
- `frontend/src/lib/__mockParts.ts` — and every `// MOCK(PARTS)` block it anchors in
  `frontend/src/composables/useWorkOrderDetail.ts`. The file's own header contains a
  removal checklist; follow it. After removal the WO "Parts used" section reads real
  part lines from the API, and the Add-Part search calls `GET /parts?search=` directly.

**Already wired (no action expected, just don't break):**
- Routes: `/parts` + `/parts/:partId` in `frontend/src/router/index.ts`
- Sidebar item "Parts Management" in `frontend/src/components/app/AppSidebar.vue`
  (visible to Admin/Manager/Technician)
- `Part` type in `frontend/src/types/index.ts`
- The WO "Parts used" section + Add-Part dialog in
  `frontend/src/views/work-orders/WorkOrderDetailView.vue`

**Recommended template (mirror the Assets view):**
- **Mirror the Assets *view* — the page-level structure: layout shell, page-section,
  page-header, tabs, and the list → drill-down detail pattern.** The Parts list/detail
  pages should look and behave like siblings of `AssetsView.vue` + `AssetDetailView.vue`.
  This keeps the app consistent and lets the team reuse the page scaffolding.
- **The *table* itself is the team's call** — columns, column order, which filter UX,
  and how the data-table is wired are implementation decisions, not prescriptions. Pick
  whatever serves a parts catalogue; just keep it visually consistent with the other
  tables in the app.
- Reuse the existing client-mode list-fetch helper and the shared data-table component
  already used across Assets, Work Orders, MRs, and PM Rules rather than reinventing.
- Attachment sections are currently implemented per-detail-view (no shared component);
  mirror whichever existing detail view the team prefers for consistency.

---

## 5. Hard requirements vs. team freedom

**Non-negotiable:**
- Consume the real `GET /parts` / `GET /parts/{id}` endpoints (no hardcoded part lists).
- Remove the `MOCK_PARTS` fallback and all `// MOCK(PARTS)` blocks, and delete
  `__mockParts.ts`.
- Respect the role-based visibility (active-only for non-Admin/Manager; inactive shown
  to Admin/Manager with a clear status).
- The result must be visually and behaviourally consistent with the rest of the app
  (same layout shells, table, badges, empty/loading/error states).

**The team decides:**
- Composable / data-source structure and naming.
- Column layout, filter UX, and how category filter options are derived (they should be
  derived from the loaded data, not hardcoded — but how is the team's call).
- Component decomposition for the detail page (sections, attachments).
- Whether/how to badge categories or units.
- Whether the detail page offers edit (it's allowed by the API but not required for this
  build).

---

## 6. Constraints & edge cases to handle

- **Inactive parts:** a non-Admin/Manager user will never receive them in the list, but
  navigating directly to an inactive part's detail will 403 — handle gracefully.
- **`erp_raw_data`** is Admin-only and currently null; render only for Admin, hide when
  absent.
- **Category filter** should track whatever the backend returns (derive from rows), so it
  stays correct when real ERP categories arrive — do not hardcode the 11 seed categories.
- **Client-mode loading** is fine for the current 55 rows. If/when the real ERP catalogue
  is large, pagination strategy may need revisiting — explicitly out of scope here.

---

## 7. Out of scope

- The "Part Request" tab (cross-subsystem link into SM ordering) — SM-owned, separate.
- Large-catalogue server-mode pagination tuning — deferred until real ERP volume is known.
- Any backend changes — all endpoints are live and seeded.
- Editing parts via UI (read-only detail is sufficient for this build; the `PATCH`
  endpoint exists if the team wants to add it later).
