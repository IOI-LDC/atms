# Asset Maintenance Status

Each ATMS-managed asset has an **Asset Maintenance Status** that represents its
maintenance-service state. This status is independent of ERP disposal, financial
treatment, capitalization, or depreciation.

> **Value rename (2026-07-02):** the canonical `maintenance_status` values are
> `enrolled` / `withdrawn` (renamed from the former `Active` / `Inactive` to
> eliminate the collision with `operational_status = 'active'`). Sub-status values
> are lowercase (`installed`, `ready`, `lih`, `dbr`, `disposed`, `scrapped`,
> `other`). The UI shows the display labels **"In maintenance program"**
> (`enrolled`) and **"Withdrawn"** (`withdrawn`).

## States

### Enrolled (`enrolled`) — displayed as "In maintenance program"

The asset is in operational use and eligible for maintenance workflows:

- Preventive Maintenance (PM) rules evaluate against enrolled assets.
- Corrective Maintenance Requests can be created for enrolled assets.
- Work Orders can be created against enrolled assets.

#### Enrolled Sub-statuses

For components and packages (`asset_kind = component` or `package`), enrolled
assets carry one of the following sub-statuses. Standalone assets
(`asset_kind = asset`) are enrolled with no sub-status.

| Sub-status | Meaning |
|---|---|
| *(none)* | Default for standalone assets. Asset is in normal operation. |
| **`installed`** | Component is currently installed inside a parent (`parent_asset_id` is set). In active service as part of an assembly. |
| **`ready`** | Component is fully maintained and available for installation. Not currently installed (`parent_asset_id IS NULL`). Spare/standby. |

### Withdrawn (`withdrawn`) — displayed as "Withdrawn"

The asset is not in active maintenance service. PM rules do not evaluate against
withdrawn assets. Withdrawn assets may still be viewable in the asset registry and
maintenance history for reference.

#### Withdrawn Sub-statuses (purely informational)

Withdrawn assets carry one of the following sub-statuses. These are **purely
informational** labels — no workflow triggers, automatic transitions, or
business rules are attached to any sub-status. They exist for categorization and
reporting only.

| Sub-status | Meaning |
|---|---|
| **`lih`** | Lost in Hole — the asset is physically inaccessible (e.g. downhole equipment that cannot be retrieved). |
| **`dbr`** | Damaged Beyond Repair — the asset is so severely damaged that repair is not economically or technically feasible. |
| **`disposed`** | The asset has been formally disposed of per organizational policy (independent of ERP disposal accounting). |
| **`scrapped`** | The asset has been dismantled, sold for scrap, or otherwise removed from the operational pool. |
| **`other`** | Any other reason the asset is not in active maintenance service, with a free-text note for context. |

## Rules

1. **Independence from ERP:** Asset Maintenance Status is an ATMS operational
   status. It does not signal ERP capitalization, depreciation, disposal, or
   financial treatment. An asset can be `withdrawn` (`disposed`) in ATMS while still
   appearing in ERP financial records.
2. **No automatic transitions:** Changing an asset's status requires an explicit
   action by an Administrator or Maintenance Manager. There are no automatic
   status transitions based on maintenance events, readings, or time.
3. **Sub-statuses carry no logic:** A sub-status of `lih` does not block PM
   evaluation (the `withdrawn` parent state already does that). A sub-status of
   `dbr` does not trigger any workflow. Sub-statuses are labels only.
4. **History visibility:** All maintenance history (MRs, WOs, readings,
   attachments) remains visible for withdrawn assets.
5. **Re-enrollment:** A withdrawn asset may be returned to `enrolled` status by an
   Administrator or Maintenance Manager at any time.
6. **installed / ready consistency:**
   - An asset with sub-status `installed` must have `parent_asset_id` set.
   - An asset with sub-status `ready` must have `parent_asset_id = NULL`.
   - These sub-statuses only apply to assets with `asset_kind = component` or `package`.
   - Swapping a component automatically updates its sub-status (`ready` → `installed`
     on install; `installed` → `ready` on removal, unless the removed component is
     being decommissioned).

## Summary

| State (`value`) | Sub-status | PM Eligible? | CM Eligible? | WO Creation? | Viewable? |
|---|---|---|---|---|---|
| Enrolled (`enrolled`) | *(none)* | Yes | Yes | Yes | Yes |
| Enrolled (`enrolled`) | `installed` | Yes | Yes | Yes | Yes |
| Enrolled (`enrolled`) | `ready` | Yes | Yes | Yes | Yes |
| Withdrawn (`withdrawn`) | any | No | No | No | Yes |

> **Note:** Booking (`is_booked`) is a separate, orthogonal availability marker
> set by Operations — it is not part of Asset Maintenance Status. See
> `ASSET_BOOKING.md`.
