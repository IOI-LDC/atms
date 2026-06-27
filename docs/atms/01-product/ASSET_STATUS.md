# Asset Maintenance Status

Each ATMS-managed asset has an **Asset Maintenance Status** that represents its
maintenance-service state. This status is independent of ERP disposal, financial
treatment, capitalization, or depreciation.

## States

### Active

The asset is in operational use and eligible for maintenance workflows:

- Preventive Maintenance (PM) rules evaluate against active assets.
- Corrective Maintenance Requests can be created for active assets.
- Work Orders can be created against active assets.

#### Active Sub-statuses

For components and packages (`asset_kind = component` or `package`), Active
assets carry one of the following sub-statuses. Standalone assets
(`asset_kind = asset`) use Active with no sub-status.

| Sub-status | Meaning |
|---|---|
| *(none)* | Default for standalone assets. Asset is in normal operation. |
| **Installed** | Component is currently installed inside a parent (`parent_asset_id` is set). In active service as part of an assembly. |
| **Ready** | Component is fully maintained and available for installation. Not currently installed (`parent_asset_id IS NULL`). Spare/standby. |

### Inactive

The asset is not in active maintenance service. PM rules do not evaluate against
inactive assets. Inactive assets may still be viewable in the asset registry and
maintenance history for reference.

#### Inactive Sub-statuses (purely informational)

Inactive assets carry one of the following sub-statuses. These are **purely
informational** labels — no workflow triggers, automatic transitions, or
business rules are attached to any sub-status. They exist for categorization and
reporting only.

| Sub-status | Meaning |
|---|---|
| **LIH** | Lost in Hole — the asset is physically inaccessible (e.g. downhole equipment that cannot be retrieved). |
| **DBR** | Damaged Beyond Repair — the asset is so severely damaged that repair is not economically or technically feasible. |
| **Disposed** | The asset has been formally disposed of per organizational policy (independent of ERP disposal accounting). |
| **Scrapped** | The asset has been dismantled, sold for scrap, or otherwise removed from the operational pool. |
| **Other** | Any other reason the asset is not in active maintenance service, with a free-text note for context. |

## Rules

1. **Independence from ERP:** Asset Maintenance Status is an ATMS operational
   status. It does not signal ERP capitalization, depreciation, disposal, or
   financial treatment. An asset can be Inactive (Disposed) in ATMS while still
   appearing in ERP financial records.
2. **No automatic transitions:** Changing an asset's status requires an explicit
   action by an Administrator or Maintenance Manager. There are no automatic
   status transitions based on maintenance events, readings, or time.
3. **Sub-statuses carry no logic:** A sub-status of "LIH" does not block PM
   evaluation (the Inactive parent state already does that). A sub-status of
   "DBR" does not trigger any workflow. Sub-statuses are labels only.
4. **History visibility:** All maintenance history (MRs, WOs, readings,
   attachments) remains visible for inactive assets.
5. **Reactivation:** An Inactive asset may be returned to Active status by an
   Administrator or Maintenance Manager at any time.
6. **Installed / Ready consistency:**
   - An asset with sub-status `Installed` must have `parent_asset_id` set.
   - An asset with sub-status `Ready` must have `parent_asset_id = NULL`.
   - These sub-statuses only apply to assets with `asset_kind = component` or `package`.
   - Swapping a component automatically updates its sub-status (Ready → Installed
     on install; Installed → Ready on removal, unless the removed component is
     being decommissioned).

## Summary

| State | Sub-status | PM Eligible? | CM Eligible? | WO Creation? | Viewable? |
|---|---|---|---|---|---|
| Active | *(none)* | Yes | Yes | Yes | Yes |
| Active | Installed | Yes | Yes | Yes | Yes |
| Active | Ready | Yes | Yes | Yes | Yes |
| Inactive | any | No | No | No | Yes |

> **Note:** Booking (`is_booked`) is a separate, orthogonal availability marker
> set by Operations — it is not part of Asset Maintenance Status. See
> `ASSET_BOOKING.md`.
