# Status Model

## Maintenance Request Statuses

### pending_review

The request has been created and is awaiting Maintenance Manager review. While
pending_review, the creator (or an Admin/Manager) may update the description,
priority, and asset. Once reviewed or cancelled, the request is immutable.

### rejected

The request has been rejected by the Maintenance Manager.

### converted

The request has been approved and atomically converted into exactly one Work
Order. There is no separate stored `approved` status.

### cancelled

The request has been cancelled while awaiting review, before approval and
conversion. Once a request is approved and atomically converted into a Work
Order, the Maintenance Request cannot be cancelled. The Work Order cancellation
workflow must be used instead.

`cancelled`, `rejected`, and `converted` are terminal Maintenance Request
statuses.

The MVP Maintenance Request status set is `pending_review`, `rejected`,
`converted`, and `cancelled`.

## Work Order Statuses

### open

The Work Order has been created from an approved Maintenance Request. It may be
unassigned initially.

### in_progress

Work has started. A Work Order cannot transition to `in_progress` unless it is
assigned to an active user with the Technician role.

### completed

The assigned Technician has submitted all required completion information.
Technician execution fields, parts used, readings, and attachments are locked
after this transition. The Work Order awaits final review by a Maintenance
Manager or Administrator.

### closed

The completed Work Order has been reviewed and finalized by a Maintenance
Manager or Administrator. Maintenance history and applicable PM baselines are
finalized. A closed Work Order is permanently immutable.

### cancelled

The Work Order has been cancelled by a Maintenance Manager or Administrator
with a required reason. Cancellation is allowed from `open`, `in_progress`, or
`completed`. A cancelled Work Order is terminal and read-only.

The MVP Work Order status set is `open`, `in_progress`, `completed`, `closed`,
and `cancelled`.

Normal transition:

`open → in_progress → completed → closed`

Cancellation transitions:

- `open → cancelled`
- `in_progress → cancelled`
- `completed → cancelled`

Closed Work Orders cannot be edited, cancelled, reopened, or transitioned to
another status.

## Asset Operational Statuses

These should be configurable as master data. Suggested defaults:

- Active
- Under Maintenance
- Down
- Inactive

Avoid financial lifecycle statuses such as capitalized/disposed unless shown as read-only ERP reference data.

## Asset Maintenance Status

Each ATMS-managed asset carries an Asset Maintenance Status independent of ERP
disposal/financial treatment. See `atms/01-product/ASSET_STATUS.md` for the
full specification.

### States

| State | Meaning |
|---|---|
| **Active** | Asset is in operational use. PM rules evaluate against active assets; CMs and WOs can be created. |
| **Inactive** | Asset is not in maintenance service. PM evaluation, CM creation, and WO creation are blocked. |

### Active Sub-statuses (component/package assets only)

| Sub-status | Meaning |
|---|---|
| *(none)* | Default for standalone assets. Normal operation. |
| **Installed** | Component is currently installed in a parent (`parent_asset_id` is set). In active service as part of an assembly. |
| **Ready** | Component is fully maintained and available for installation. Spare/standby (`parent_asset_id IS NULL`). |

### Inactive Sub-statuses (purely informational)

| Sub-status | Meaning |
|---|---|
| **LIH** | Lost in Hole |
| **DBR** | Damaged Beyond Repair |
| **Disposed** | Formally disposed |
| **Scrapped** | Sold for scrap or removed |
| **Other** | Other reason, with free-text note |

### Rules

- Asset Maintenance Status is independent of ERP financial treatment.
- Only Administrator or Maintenance Manager may change an asset's status.
- No automatic transitions. All status changes are explicit.
- All maintenance history remains visible regardless of status.
- Inactive assets may be reactivated at any time by Admin/Manager.
- `Installed` requires `parent_asset_id IS NOT NULL`; `Ready` requires `parent_asset_id IS NULL`. These sub-statuses only apply to `asset_kind = component` or `package`.
- Swapping a component auto-updates sub-status: Ready → Installed on install; Installed → Ready on removal (or Inactive/DBR if decommissioned).

## Asset Booking

Booking is an **availability marker** orthogonal to both operational and
maintenance status. Operations books an asset to guarantee it is reserved and
available for a specific Job or Project. See `atms/01-product/ASSET_BOOKING.md`
for the full specification.

| Field | Type | Default | Meaning |
|---|---|---|---|
| `is_booked` | boolean | `false` | `true` = reserved by Operations for a Job/Project; `false` = freely available |

### Rules

- Booking does not belong to `operational_status` or `maintenance_status` — it is a separate, independent axis.
- Booking does NOT gate MR creation, WO creation, or PM evaluation. An asset can be booked and still be maintained.
- Booking auto-clears (`is_booked = false`) on any location change (`UpdateAssetLocation`).
- Booking auto-clears when an asset is deactivated (`is_active = false`) or its `maintenance_status` becomes `Inactive`. A decommissioned asset cannot remain booked.
- Booking survives maintenance events (WO open/start/complete/close) — only location change or inactivation releases it.
- No Job/Project/client reference is stored in ATMS; the booking is purely a binary availability flag.
- Only Administrator, Maintenance Manager, and Logistics may toggle booking.
