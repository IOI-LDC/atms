# Asset Booking

> **Status:** Implemented (2026-06-27).

## Concept

**Why booking exists:** Operations needs to guarantee that a specific asset is
reserved and available for a future **Job or Project**. Without a booking marker,
Operations has no visibility into which assets are already earmarked — leading to
double-allocation, surprise relocations, or an asset being sent out when it was
already promised to a client/job.

Operations may **book** an asset to guarantee its availability for a specific Job
or Project. A booked asset is physically available (`operational_status = active`)
and in the maintenance program (`maintenance_status = Active`), but is earmarked —
it should not be reassigned or relocated without explicit unbooking.

Booking is purely an availability marker. It is **not** a maintenance or physical
state mutation.

## Field

| Column | Type | Default |
|---|---|---|
| `is_booked` | boolean | `false` |

## States

| `is_booked` | Meaning |
|---|---|
| `false` (default) | Asset is freely available for any use. |
| `true` | Asset is reserved by Operations for a specific Job/Project. |

No additional fields track *what* it is booked for — that context lives in the
Job/Project system, not in ATMS.

## Who can toggle booking

| Role | Book | Unbook |
|---|---|---|
| Administrator | ✅ | ✅ |
| Maintenance Manager | ✅ | ✅ |
| Logistics | ✅ | ✅ |
| Technician | ❌ | ❌ |
| Requester | ❌ | ❌ |

## Auto-release trigger

When an asset's location changes — via **any** path (dedicated location update,
general asset edit, or future bulk moves) — `is_booked` is automatically set to
`false`. Rationale: if Operations moved the asset, the original booking is no
longer relevant.

Implementation point: add `$lockedAsset->is_booked = false` inside
`UpdateAssetLocation::execute()` when `$fromLocationId !== $toLocation->id`.

## Interaction with other statuses

### `operational_status`
Booking is independent. A booked asset can be `active`, `under_maintenance`, or
`down`. Booking does not change or depend on operational status.

### `maintenance_status`
Booking is independent. A booked asset can be `Active` or `Inactive` in the
maintenance program.

### Maintenance workflows
**Booking does NOT gate MR creation, WO creation, or PM evaluation.** An asset
that is booked can still have maintenance requests created, work orders opened,
and preventive maintenance triggered against it.

Booking may serve as an *informational* signal to a human reviewer ("this asset
is reserved — consider whether the maintenance request should be deferred"), but
it carries no automated enforcement.

### Asset inactivation / deletion
If an asset is deactivated (`is_active = false`) or its `maintenance_status`
becomes `Inactive`, `is_booked` should be set to `false`. A decommissioned asset
cannot be booked.

## What booking is NOT

- **NOT a maintenance sub-status.** It does not belong in `maintenance_sub_status`.
- **NOT an operational status.** It does not belong in `operational_status`.
- **NOT a location.** Booking does not imply or assign a physical location.
- **NOT a reservation system.** There is no expiry, no job/order reference stored,
  and no conflict detection against other bookings.

## Planned API

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/assets/{asset}/book` | Set `is_booked = true` |
| `POST` | `/api/assets/{asset}/unbook` | Set `is_booked = false` |

Both endpoints require the `toggleBooking` gate (Admin, Manager, Logistics).

`is_booked` is also exposed in `AssetResource` and filterable in `GET /api/assets`.

## Rules summary

1. Booking is a binary state — `is_booked` (true/false).
2. No reference to the booking reason (Job/Project/client) is stored in ATMS.
3. Booking is toggled via dedicated endpoints — not by editing `operational_status`
   or `maintenance_status`.
4. Auto-release on any location change. No manual unbook required after a move.
5. Booking survives maintenance events — WO creation, completion, and closure do
   not affect it.
6. Booking does not block any maintenance workflow.
7. Inactive/decommissioned assets cannot be booked; booking auto-clears on inactivation.
8. Admin, Maintenance Manager, and Logistics can toggle booking.

## Implementation notes

- Migration: `2026_06_27_125145_add_is_booked_to_assets_table.php` — `$table->boolean('is_booked')->default(false)`
- Model: `Asset::$fillable` + `$casts['is_booked'] = 'boolean'`; `static::updating()` auto-clears booking on deactivation/inactivation
- Action: `App\Actions\Assets\ToggleAssetBooking` — book/unbook with audit log (`asset.booked` / `asset.unbooked`), idempotency guards, inactive-asset block
- Policy: `AssetPolicy::toggleBooking()` — Admin, Manager, Logistics
- Controller: `App\Http\Controllers\AssetBookingController` — `book()` / `unbook()` returning `AssetResource`
- Auto-clear on location change: `UpdateAssetLocation::execute()` sets `is_booked = false` when location differs
- Auto-clear on inactivation: `Asset` model `static::updating()` listener clears `is_booked` when `is_active` becomes `false` or `maintenance_status` becomes `Inactive`
- Tests: `tests/Feature/Assets/AssetBookingTest.php` — 14 tests (auth, behaviour, auto-release, inactivation, non-interference)
