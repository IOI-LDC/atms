# Dashboard KPIs — Backend → Frontend Handoff

> **Purpose:** Everything the frontend agent needs to build the 9-card Dashboard +
> "Recently Relocated Assets" widget from `GET /api/dashboard/kpis`. Self-contained —
> you do not need to open the `backend/` folder.
>
> For the exhaustive per-field reference, see
> [`BACKEND_API_REFERENCE.md` §Dashboard](./BACKEND_API_REFERENCE.md#dashboard).
> For auth/CSRF/pagination conventions, see
> [`BACKEND_API_HANDOFF.md`](./BACKEND_API_HANDOFF.md).

**Status:** Verified against the implemented backend (Laravel 13 / PHP 8.4 /
PostgreSQL 17) on 2026-07-03. 11 feature tests passing.

---

## 1. The endpoint

```
GET /api/dashboard/kpis
```

| | |
|---|---|
| **Auth** | Cookie/session (SPA) — any authenticated role |
| **Authorization** | Every role sees the **full payload** (not role-filtered) |
| **Method** | GET, no query params, no body |
| **Cost** | A handful of small aggregate queries over a 90-day window. Safe to call on dashboard mount; refresh on demand. |
| **Returns** | `200` JSON — a single object (no `data` wrapper) |

```ts
// src/types/dashboard.ts
import type { RelocatedAssetItem } from '.'

export interface DashboardKpiResponse {
  window: { days: 90; from: string; to: string }   // ISO 8601 bounds (UTC)
  kpis: {
    mtbf: { days: number | null }
    failure_rate: { failures: number; per_day: number }
    mttr: { hours: number | null }
    pm_compliance: { compliant: number; total: number; percentage: number | null }
    avg_mr_duration: { hours: number | null }
    avg_wo_duration: { hours: number | null }
  }
  recently_relocated_assets: RelocatedAssetItem[]
}

export interface RelocatedAssetItem {
  id: number
  asset_id: number
  asset: { id: number; name: string; erp_asset_code: string; asset_tag: string }
  from_location: { id: number; name: string }
  to_location: { id: number; name: string }
  effective_at: string
  reason: string | null
  notes: string | null
  changed_by_user_id: number
  created_at: string
}
```

```ts
// usage
import { api } from '@/lib/api'
import type { DashboardKpiResponse } from '@/types/dashboard'

export function useDashboardKpis() {
  return useAsyncData('dashboard-kpis', () =>
    api.get<DashboardKpiResponse>('/dashboard/kpis').then(r => r.data),
  )
}
```

> No CSRF needed for a GET. No cursor pagination — it is a single aggregate object.

---

## 2. The 9-card layout → field mapping

The dashboard is a 3×3 grid plus a relocated-assets list.

### Row 1 — Operational Status
> **These three come from the existing `GET /api/dashboard` endpoint** (already
> shipped, role-adaptive). The KPI endpoint does **not** duplicate them. Use
> `summary.*` counts from `/api/dashboard` for these cards.

| Card | Source | Field |
|---|---|---|
| Pending Maintenance Requests | `GET /api/dashboard` | `summary.pending_maintenance_requests` |
| Open Work Orders | `GET /api/dashboard` | `summary.open_work_orders` |
| Overdue PM | `GET /api/dashboard` | `summary.overdue_pm_assignments` |

### Row 2 — Reliability  →  `GET /api/dashboard/kpis` → `kpis.*`

| Card | Field | Unit | Display when `null` |
|---|---|---|---|
| MTBF | `kpis.mtbf.days` | days | "—" (no failures in window) |
| MTTR | `kpis.mttr.hours` | hours | "—" (no corrective closed WOs) |
| Failure Rate | `kpis.failure_rate.failures` (+ `per_day`) | count | "0" |

### Row 3 — Process Performance  →  `GET /api/dashboard/kpis` → `kpis.*`

| Card | Field | Unit | Display when `null` |
|---|---|---|---|
| PM Compliance | `kpis.pm_compliance.percentage` (+ `compliant`/`total`) | % | "—" (no PMs due) |
| Avg MR Duration | `kpis.avg_mr_duration.hours` | hours | "—" |
| Avg WO Duration | `kpis.avg_wo_duration.hours` | hours | "—" |

### Widget — Recently Relocated Assets  →  `GET /api/dashboard/kpis`

| Item | Field |
|---|---|
| `recently_relocated_assets[]` (max 5, newest first) | asset name/tag, from → to location, effective_at, reason |

---

## 3. What each KPI means (so labels/copy are accurate)

| KPI | Definition | Key caveat |
|---|---|---|
| **MTBF** | Mean Time Between Failures, **calendar basis** = `90 days / corrective failures in window`. | A "failure" = a corrective Maintenance Request (`is_preventive = false`). |
| **Failure Rate** | Corrective failures in the window, plus failures-per-day (`failures / 90`). | Inverse of MTBF on a per-day basis. |
| **MTTR** | Mean Time To Repair = average `assigned_at → closed_at` of **corrective** Work Orders closed in window (full repair cycle, not execution-only). |
| **PM Compliance** | Of **date-triggered** PM requests due in the window, the share whose linked WO **closed on or before** its `trigger_date`. | Reading-triggered PMs are excluded (no calendar due date). On-time anchor is `wo.closed_at`, not `completed_at`. |
| **Avg MR Duration** | Average `created_at → resolved` for MRs that reached a terminal status (converted/rejected via `reviewed_at`, cancelled via `cancelled_at`) in the window. |
| **Avg WO Duration** | Average `created_at → closed_at` for Work Orders closed in the window. |
| **Recently Relocated Assets** | Latest 5 `asset_location_histories` with `effective_at` inside the 90-day window, newest first. | Older moves drop off; the list can be shorter than 5. |

---

## 4. Null handling (important)

Several scalars are **`null`, not `0`**, when there is no data in the window — this
means "no basis to compute", which is semantically different from a real zero.

- `mtbf.days`, `mttr.hours`, `avg_mr_duration.hours`, `avg_wo_duration.hours`,
  `pm_compliance.percentage` → render **`—`** (em dash), not `0`.
- `failure_rate.failures` and `pm_compliance.compliant` / `total` are always
  integers (0 when empty) — safe to render as numbers.

```ts
const dash = kpis.mtbf.days                 // number | null
const text = dash == null ? '—' : `${formatDays(dash)}`
```

---

## 5. Suggested formatting

| KPI | Format helper idea |
|---|---|
| MTBF | `≥ 1 day` → `${days}d`; fractional → `${days.toFixed(1)}d`. |
| MTTR / durations (hours) | `< 24h` → `${h.toFixed(1)}h`; `≥ 24h` → `${Math.floor(h/24)}d ${Math.round(h%24)}h`. |
| Failure Rate | primary: `${failures}` count; subtitle: `${per_day.toFixed(3)}/day` (or "per 30d": `per_day*30`). |
| PM Compliance | primary: `${percentage}%`; subtitle: `${compliant} / ${total} on time`. |
| Relocated item | `${asset.name}` (or `asset_tag`); `${from_location.name} → ${to_location.name}`; `effective_at` formatted in company TZ. |

Timestamps in `window.from` / `window.to` and `effective_at` are ISO 8601 UTC.
Format for display in the company timezone (`Africa/Tripoli` default) client-side.

---

## 6. Integration notes

1. **Two calls feed the full dashboard.** `GET /api/dashboard` (Row 1 counts +
   widget lists, role-adaptive) **and** `GET /api/dashboard/kpis` (Rows 2–3 +
   relocated list). Fire both in parallel on dashboard mount.
2. **The window is fixed at 90 days** and is not parameterised today. Surface
   `window.from` → `window.to` as a subtitle ("Last 90 days") so users know the
   scope. If a future `?period=` param is added, it will appear in `window.days`.
3. **Refresh strategy:** these are live aggregates. Provide a manual refresh;
   polling is optional (every few minutes is plenty — do not poll rapidly).
4. **Relocated list click-through:** each item's `asset.id` can deep-link to
   Asset Detail → Location History tab (`GET /api/assets/{asset}/location-history`).
5. **No write side:** the KPI endpoint is read-only. Relocation entries are
   created by the AM location-update flow (`POST /api/assets/{asset}/location`).

---

## 7. Sample response

```json
{
  "window": { "days": 90, "from": "2026-04-04T01:17:48Z", "to": "2026-07-03T01:17:48Z" },
  "kpis": {
    "mtbf":            { "days": 45.0 },
    "failure_rate":    { "failures": 2, "per_day": 0.0222 },
    "mttr":            { "hours": 6.0 },
    "pm_compliance":   { "compliant": 1, "total": 3, "percentage": 33.3 },
    "avg_mr_duration": { "hours": 36.0 },
    "avg_wo_duration": { "hours": 36.0 }
  },
  "recently_relocated_assets": [
    {
      "id": 12,
      "asset_id": 7,
      "asset": { "id": 7, "name": "Top Drive 01", "erp_asset_code": "A-001", "asset_tag": "L-ROTOR-001-0007" },
      "from_location": { "id": 3, "name": "Yard B" },
      "to_location":   { "id": 5, "name": "Rig 12" },
      "effective_at": "2026-07-01T08:00:00Z",
      "reason": "Deployment",
      "notes": null,
      "changed_by_user_id": 4,
      "created_at": "2026-07-01T08:00:00Z"
    }
  ]
}
```
