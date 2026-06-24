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

- Storekeeper transfers parts from Main Store to a Virtual Store bin.
- A `stock_movement` record is created: `from = Main Store, to = Virtual Store
  (bin), type = transfer_in`.
- Parts in the Virtual Store are still in SM inventory — they are at a
  different location, not consumed.

### 2. Consumption — During the day

- A technician records parts consumed against a Work Order.
- The part is sourced from the Virtual Store bin.
- A `stock_movement` record is created: `from = Virtual Store (bin), type =
  consumption`.
- This decrements the Virtual Store balance AND the overall SM inventory.

### 3. Return — End of day (default)

- At end of day, all stock remaining in the Virtual Store must be returned to
  the Main Store.
- A `stock_movement` record is created: `from = Virtual Store (bin), to = Main
  Store, type = return`.
- System auto-flags all Virtual Store bins with `remaining_stock > 0` at the
  configured end-of-day time.

### 4. Overnight hold — Manager exception

- The Maintenance Manager may approve specific parts/bins to remain in the
  Virtual Store overnight without being returned.
- The approval is audited (who approved, when, what parts, which bin).
- Approved parts are flagged as `overnight_hold = true` with an
  `overnight_approved_by` and `overnight_approved_at` timestamp.
- **Crucially:** Overnight-held parts MUST be consumed on the next working
  day. The system flags any overnight-held stock that is still unconsumed at
  the following end-of-day — it is then auto-returned with no further override
  allowed.

## Data Model (proposed)

### Locations

Extend the existing `locations` table with:

| Column | Type | Purpose |
|---|---|---|
| `location_type` | enum: `warehouse`, `workshop` | `warehouse` = main physical store; `workshop` = virtual store |
| `parent_location_id` | FK → locations.id, nullable | For bins: which Virtual Store they belong to. `NULL` for the Virtual Store itself. |

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

1. Query all Virtual Store locations (`location_type = workshop`).
2. For each, calculate `remaining_stock = sum(transfers_in) - sum(consumption) -
   sum(returns)`.
3. Flag all remaining stock for return.
4. For items with `overnight_hold = true`:
   - If this is a *new* overnight hold (approved today): allowed to stay.
   - If this overnight hold was approved *yesterday* and the stock is still
     unconsumed: auto-return, no override. Log the forced return to the audit
     trail.

## Workflow Summary

```
1. Storekeeper: Transfer parts from Main Store → Virtual Store Bin
2. Technician: Record parts on WO, sourced from Virtual Store Bin
3. End of Day (system):
   a. Flag unconsumed stock in all Virtual Store bins
   b. Return flagged stock → Main Store
4. Exception: Manager approves overnight hold for specific parts
   → Parts must be consumed next day
   → If not consumed, auto-return (no further override)
```

## Open Questions

| # | Question |
|---|---|
| Q1 | Bin granularity — one Virtual Store per physical workshop, or per-technician bins? (e.g. "Workshop Bay 1" vs. "Ahmed's bench") |
| Q2 | Does the manager approve at the part level or the bin level? (e.g. "all of Bay 1 stays" vs. "these 3 specific rotors stay") |
| Q3 | Is the end-of-day return a system-enforced workflow step (storekeeper must confirm) or fully automated? |
| Q4 | Should overnight-held stock auto-trigger a notification to the Maintenance Manager if unconsumed? |
