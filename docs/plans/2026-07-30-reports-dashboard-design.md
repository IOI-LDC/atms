# Reports and Current Dashboard Enhancement Design

**Status:** Approved on 2026-07-30

## Objective

Finish the report-dimension changes already captured by R-007 and enhance the
current `/dashboard` implementation. All asset and part references must follow
the shared identity presentation rules.

## Decisions

- The current `/dashboard` design in `DashboardPlaceholderView.vue` remains the
  public dashboard and is the only dashboard design in scope.
- Changes build on that current design; its KPIs, role scoping, queues, date
  windows, layout, and visual presentation remain intact.
- Reports use the explicit dimensions `maintenance_category`, `asset_class`,
  and `size`. The legacy `category` contract is rejected.
- Maintenance Category and Size are current asset attributes. Historical
  activity is not snapshotted or retroactively reconstructed.
- Parts Consumption exposes both Asset Size and Part Size.
- ERP asset and part codes remain stored but are not displayed, searched, or
  used as visible report identity.

## Report contracts

| Report | Supported `group_by` values |
|---|---|
| MTBF | `asset`, `maintenance_category`, `asset_class`, `size`, `location` |
| MTTR | `asset`, `maintenance_category`, `asset_class`, `size`, `technician` |
| Bad Actors | `asset`, `maintenance_category`, `asset_class`, `size`, `location` |

The old `group_by=category` value returns a validation error.

### Group keys and labels

- **Asset:** stable asset ID as the key. The response also carries the shared
  Asset Identity so the frontend renders Name, Serial Number, Size, and
  Maintenance Category without concatenation.
- **Maintenance Category:** category code as the key and category name as the
  label. A missing category uses key `uncategorised` and label
  `Uncategorised`.
- **Asset Class:** `fa_subclass_code` as the key and label. A missing class uses
  key `unclassified` and label `Unclassified`.
- **Size:** canonical numeric inches, such as `6.75000`, as the key and O&G
  notation, such as `6 3/4"`, as the label. A missing size uses key
  `unspecified` and label `Unspecified`.
- **Location/Technician:** existing stable IDs remain the keys; their names
  remain the labels.

Equivalent size inputs therefore resolve to one exact group.

## Parts Consumption

The report remains a read-only aggregation of parts recorded on completed or
closed work orders.

Each row exposes:

- a nested Part Identity containing name, supplier Part Number, Part Size,
  Maintenance Category, unit of measure, and the ERP availability snapshot;
- `asset_class`;
- `asset_size` in O&G notation and `asset_size_inches` as the canonical key;
- quantity, line-item count, and work-order count.

Rows are grouped by part, Asset Class, and Asset Size. Part Size and Part
Maintenance Category are properties of the part and do not create additional
aggregation ambiguity. The visible ERP part code and the API field
`part_code` are removed.

## Current dashboard enhancement

`DashboardPlaceholderView.vue`, which is already the route target for
`/dashboard`, remains the implementation being developed. The KPI definitions,
role-dependent payloads, and current mosaic design do not change.

The following queues use the existing `AssetIdentity` component:

- Pending Maintenance Requests;
- Open Work Orders;
- Overdue PM Assignments;
- Recently Relocated Assets;
- Recently Closed Work Orders.

MR/WO numbers remain separate record identifiers. They must not be concatenated
with the asset name. Secondary text continues to show dates, requestors,
technicians, locations, and statuses.

The backend dashboard queries already eager-load `asset.maintenanceCategory`,
and their resources already serialize `AssetIdentityResource`. The dashboard
change is therefore primarily a frontend identity-rendering change, protected
by API regression tests. It does not require a route swap.

## Error handling and compatibility

- Unsupported report dimensions fail validation; they do not silently fall
  back to Asset.
- Null category and size values remain visible in explicit buckets.
- Report filters retain existing authorization and date validation.
- Parts Consumption cursor pagination remains deterministic after Asset Size is
  added to its grouping and ordering.
- No schema migration or historical-data rewrite is required.

## Verification

- Backend feature tests cover each new dimension, stable keys, null buckets,
  equivalent size normalization, legacy-category rejection, Parts Consumption
  identity, and pagination stability.
- Dashboard tests confirm every embedded asset contains the identity fields.
- Frontend type-check and production build verify contract parity.
- Browser verification covers the existing `/dashboard` route and mosaic
  design, role-specific widgets, identity badges, badge wrapping, empty states,
  and report filters.

## Out of scope

- New dashboard KPIs, filters, charts, or layout redesign;
- historical category or size snapshots;
- a custom report builder or BI/warehouse expansion;
- labor productivity, financial, inventory valuation, or Store Management
  reporting;
- adding Size grouping to reports outside the approved scope.
