# Virtual Store — Maintenance Workshop Stock

## Concept

The SM (Store Management) subsystem supports **Virtual Stores** — named
workshop locations with bins/rooms where parts can be temporarily staged for
technicians to consume during a shift. The Virtual Store eliminates the need
for technicians to walk back to the main warehouse for every part, while
keeping inventory accountable.

A Virtual Store is a location within SM with `location_type = workshop`. It
holds parts that have been transferred from the Main Store but not yet consumed
against a Work Order.

## Daily Cycle

```
 START OF DAY                    DURING DAY                     END OF DAY
 ┌──────────┐                   ┌──────────────┐              ┌──────────────┐
 │  MAIN    │──transfer────────→│   VIRTUAL    │──consumed───→│  CONSUMED    │
 │  STORE   │                   │   STORE      │   (on WO)    │  (done) ✅   │
 └──────────┘                   │  (workshop)  │              └──────────────┘
                                │              │──returned───→│  MAIN STORE  │
                                │   Bins:      │              │  (return) ↩  │
                                │   Bay 1      │              └──────────────┘
                                │   Bay 2      │
                                └──────────────┘
```

## Rules

### 1. Transfer in — Start of day

- Storekeeper transfers parts from Main Store to a workshop bin.
- A `stock_movement` record is created: `from = Main Store (warehouse), to =
  workshop bin, type = transfer_in`.
- Parts in the Virtual Store are still in SM inventory — they are at a
  different location, not consumed.

### 2. Consumption — During the day

- A technician records parts consumed against a Work Order.
- The part is sourced from a workshop bin.
- A `stock_movement` record is created: `from = workshop bin, type =
  consumption`.
- This decrements the Virtual Store balance AND the overall SM inventory.

### 3. Return — End of day (auto-flagged)

- At end of day, all stock remaining in workshop bins is auto-flagged for
  return by the system. No storekeeper confirmation needed.
- Flagged stock is returned to the Main Store.
- A `stock_movement` record is created: `from = workshop bin, to = Main Store
  (warehouse), type = return`.
- The end-of-day job queries all `workshop_bin` locations and flags any
  `remaining_stock > 0` at the configured time.

### 4. Overnight hold — Manager exception (per part)

- The Maintenance Manager may approve **specific parts** (individual line items)
  to remain in the Virtual Store overnight without being returned. Approval is
  per part, not per bin.
- The approval is audited (who approved, when, which part, quantity, which bin).
- Approved parts are flagged as `overnight_hold = true` with an
  `overnight_approved_by` and `overnight_approved_at` timestamp.
- **Crucially:** Overnight-held parts MUST be consumed on the next working
  day. The system flags any overnight-held stock that is still unconsumed at
  the following end-of-day — it is then auto-returned with no further override
  allowed. The flag is visible to the storekeeper and Maintenance Manager on
  the SM dashboard.

## Data Model (proposed)

### Locations

Extend the existing `locations` table with:

| Column | Type | Purpose |
|---|---|---|
| `location_type` | enum: `warehouse`, `workshop_bin` | `warehouse` = main physical store; `workshop_bin` = a named zone within the single workshop (e.g. "Bay 1", "Bay 2") |

The Virtual Store itself is implicit — all `workshop_bin` locations together
constitute the Virtual Store. Stock in any `workshop_bin` is subject to the
end-of-day rules.

### Stock Movements

A `stock_movements` table in SM:

| Column | Type | Purpose |
|---|---|---|
| `part_id` | FK → parts.id | |
| `from_location_id` | FK → locations.id, nullable | Source location (`NULL` if initial receipt) |
| `to_location_id` | FK → locations.id, nullable | Destination (`NULL` if consumption) |
| `quantity` | decimal | |
| `movement_type` | enum: `transfer`, `consumption`, `return`, `receipt` | |
| `work_order_id` | FK → work_orders.id, nullable | If consumed against a WO |
| `overnight_hold` | boolean, default false | Manager-approved hold |
| `overnight_approved_by` | FK → users.id, nullable | Who approved the overnight hold |
| `overnight_approved_at` | timestamp, nullable | When it was approved |
| `created_at` | timestamp | |

### End-of-Day Job

A scheduled job runs at the configured end-of-day time (e.g. 18:00):

1. Query all `workshop_bin` locations.
2. For each, calculate `remaining_stock = sum(transfers_in) - sum(consumption) -
   sum(returns)`.
3. Auto-flag all remaining stock for return.
4. For line items with `overnight_hold = true`:
   - If this is a *new* overnight hold (approved today): allowed to stay.
   - If this overnight hold was approved *yesterday* and the stock is still
     unconsumed: auto-return, no override. Log the forced return to the audit
     trail and display on the SM dashboard.

## Workflow Summary

```
1. Storekeeper: Transfer parts from Main Store → Workshop Bin
2. Technician: Record parts consumed on WO, sourced from Workshop Bin
3. End of Day (system auto-flag):
   a. Auto-flag all unconsumed stock in workshop bins
   b. Flagged stock → returned to Main Store
4. Exception: Manager approves overnight hold for specific part line items
   → Approved parts stay in the workshop bin overnight
   → MUST be consumed the next working day
   → If not consumed: auto-returned with no further override
```

## Decisions

| # | Question | Decision |
|---|---|---|
| Q1 | Bin granularity | **One Virtual Store per physical workshop.** LDC has one workshop. The Virtual Store represents the entire workshop floor. Bins are named zones within the workshop (e.g. "Workshop Bay 1", "Workshop Bay 2"). Not per-technician. |
| Q2 | Manager approval scope | **Per part/line item.** The Maintenance Manager approves specific parts to stay overnight, not entire bins. Each line item in the Virtual Store is individually flagged. |
| Q3 | End-of-day return | **System auto-flags.** Unconsumed stock is automatically flagged by the system at the configured end-of-day time. No storekeeper confirmation required — the flag is the trigger for return. |
| Q4 | Notification on unconsumed overnight stock | Covered by auto-flagging. Overnight-held stock that is not consumed the following day is auto-flagged for return with no override allowed. The flag is visible to the storekeeper and the Maintenance Manager on the SM dashboard. |
