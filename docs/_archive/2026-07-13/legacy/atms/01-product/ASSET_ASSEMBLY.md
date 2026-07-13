# Asset Assembly (Package / Component)

> **Status:** In progress — structure and core rules decided. Q1–Q4 resolved. Q5:
> Q1–Q5 resolved.

## Concept

Some assets are composed of other assets. For example, a mud motor contains a
power section, which itself contains a rotor and a stator. The rotor and stator
are independent assets with their own maintenance lifecycles — they can be
swapped, refurbished, and tracked individually, even while installed inside a
parent assembly.

This is not a passive parts list (bill of materials). Each component is a
**full ATMS asset** with its own MRs, WOs, readings, and history.

## Terminology

| Term | Definition |
|---|---|
| **Asset** | A single, indivisible unit. Cannot be broken into sub-assets. Leaf node only. |
| **Package** | An asset that **can** contain child assets. A package may also be a component of a larger package. |
| **Component** | An asset that **can** be installed inside a parent. A component may also be a package (if it contains further children). |
| **Root** | A package with no parent — sits at the top of an assembly tree. |

These are not mutually exclusive — a Power Section is both a Package (contains
Rotor + Stator) and a Component (installed inside the Motor).

**Example assembly tree:**

```
Motor (Package, Root)
 ├── Power Section (Package, Component)
 │    ├── Rotor (Asset, Component)
 │    └── Stator (Asset, Component)
 └── Bearing Assembly (Package, Component)
      ├── Radial Bearing (Asset, Component)
      └── Thrust Bearing (Asset, Component)
```

## Data Model

### `assets` table additions

| Column | Type | Purpose |
|---|---|---|
| `parent_asset_id` | FK → `assets.id`, nullable | If set, this asset is currently installed inside the parent. `NULL` = standalone, root, or ready spare. |
| `asset_kind` | enum: `asset`, `package`, `component` | Declares what role this asset **can** play. See below. |

### `asset_kind` rules

| Kind | Can have parent? | Can have children? | Example |
|---|---|---|---|
| `asset` | No | No | Rotor, Stator (leaf unit) |
| `package` | Yes | Yes | Motor, Power Section (can be both parent and child) |
| `component` | Yes | No | Radial Bearing (designed to be installed, but has no children) |

If an asset needs to both contain children AND be installable inside a parent,
use `package` — a package is already allowed to have a parent.

### `asset_assembly_history` table (new)

Dedicated audit trail for every install/removal event:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | |
| `component_id` | FK → `assets.id` | The component being installed or removed |
| `parent_id` | FK → `assets.id` | The parent it was attached to |
| `installed_at` | timestamp | When the component was installed |
| `removed_at` | timestamp, nullable | When it was removed (`NULL` while still installed) |
| `installed_by` | FK → `users.id` | Who performed the install |
| `removed_by` | FK → `users.id`, nullable | Who performed the removal |
| `reason` | text, nullable | Reason for removal (e.g. "preventive swap", "rotor worn", "upgrade") |
| `created_at` / `updated_at` | timestamps | |

When a component is installed: insert a row with `installed_at = now`, `removed_at = NULL`.
When a component is removed: set `removed_at = now` on the active row (the one with `removed_at IS NULL`).
The history of all install/removal events for a component is always available.

## Parent Relationship Rules

1. **One parent at a time.** `parent_asset_id` is a single nullable FK. A
   component can only be in one place.
2. **Swap is explicit.** Remove old component (`parent_asset_id = NULL`, close
   its history row), install new component (`parent_asset_id = <parent>`, insert
   new history row). Both happen in a single operation or transaction.
3. **Cycle prevention.** A component's parent cannot be itself or any of its
   own descendants. Enforced in application logic.
4. **Spare components.** A component with `parent_asset_id = NULL` and an open
   assembly-history row (removed_at not null) is a *spare* — available for
   installation.

## Work Order Behaviour (Q1 — DECIDED)

**One WO per asset.** The WO lives on the asset being maintained. Component
statuses are updated separately during the WO — they do not get their own WO
from the same trigger.

**Scenario: Preventive swap on a Motor at 500 hrs.**

```
1. Motor PM rule fires at 500 hrs → MR created on MOTOR
2. Manager approves → WO created on MOTOR
3. WO on MOTOR includes:
   - Remove Rotor-A (parent_asset_id → NULL, close history row)
   - Install Rotor-B (parent_asset_id → MOTOR, insert history row)
   - Update Rotor-A status to Active/Ready (or Inactive/DBR if worn)
   - Update Rotor-B status to Active/Installed
4. Rotor-A is now a spare. If it needs refurbishment:
   → Create separate Corrective MR on ROTOR-A
   → Approve → WO on ROTOR-A for refurbishment
   (This is independent of the Motor's WO lifecycle.)
```

Key principle: the parent's WO triggers the swap and updates component statuses.
If the removed component needs its own maintenance, that is a separate MR → WO
chain initiated by a technician or automatically flagged.

## Component Operating Hours (Q2 — RECOMMENDATION)

Components accumulate operating hours/depth while installed in a parent. When
swapped, their accumulated hours freeze. When reinstalled later, they continue.

**Decision: Derived calculation (Option A).**

- No virtual meter readings stored on the component.
- PM evaluation queries: *"parent's current hours − hours at which this
  component was installed"* (from `asset_assembly_history.installed_at`
  cross-referenced with the parent's confirmed meter reading at that time).
- When a component is removed, its total accumulated runtime for that
  installation is calculated and stored in the history row as `accumulated_hours`.

**Example:**

```
Motor at 0 hrs → Rotor-A installed
Motor at 300 hrs → Rotor-A removed (accumulated: 300 hrs on this install)
Motor at 300 hrs → Rotor-B installed
Motor at 500 hrs → Rotor-B has 200 hrs (500 − 300), Rotor-A has 300 hrs total
```

If Rotor-A is later refurbished and reinstalled, the system tracks two
installation periods: period 1 (0–300 hrs) + period 2 (future). Total
accumulated = sum of all periods.

**Alternative (Option B — if manual confirmation is needed later):**
Add a virtual meter reading type. The system auto-records confirmed readings at
swap time. This allows manual override/confirmation but adds data volume and
sync complexity. Start with Option A, revisit if needed.

## Readiness Sub-Statuses (Q4 — DECIDED)

Expand Asset Maintenance Status to distinguish between a component that is
installed vs. a spare that is ready for deployment.

### Active sub-statuses (new)

| Sub-status | Meaning |
|---|---|
| *(none)* | Default for standalone assets (not a component). Asset is in normal operation. |
| **Installed** | Component is currently installed in a parent (`parent_asset_id IS NOT NULL`). In active service. |
| **Ready** | Component is fully maintained and available for installation. Not currently installed (`parent_asset_id IS NULL`). |

### Inactive sub-statuses (unchanged)

LIH, DBR, Disposed, Scrapped, Other — as before. No change.

### Rules

- `Installed` and `Ready` only apply to assets with `asset_kind = component` or `package`.
- Setting a component to `Installed` when `parent_asset_id IS NULL` is an invalid state (enforced).
- Setting a component to `Ready` when `parent_asset_id IS NOT NULL` is an invalid state (enforced).
- Standalone assets (`asset_kind = asset`) stay at Active with no sub-status.

## PM Rules on Packages and Components (Q5 — RECOMMENDATION)

**Principle:** Parent PM and component PM are **independent schedules**. The
parent PM guarantees the asset gets inspected at regular intervals. The
component PM guarantees individual components don't exceed their own limits.
They complement each other — neither replaces the other.

### How it works

1. **Motor PM rule** (every 500 hrs) fires → MR → WO on Motor.
   This gets the motor into the workshop. The WO scope is: inspect motor,
   cross-check components, perform any needed work on the motor itself.

2. **Rotor PM rule** (every 400 hrs) fires independently → MR → WO on Rotor.
   If the rotor hits 400 hrs while the motor is at 350 hrs, the rotor PM triggers
   a separate MR. The decision is: do we pull the motor early to swap the rotor,
   or defer until the motor's own service at 500 hrs? This is an operational
   call made by the Maintenance Manager.

3. **Cross-check at parent service.** When the Motor WO is open, the UI displays
   all child components with a simple PM status indicator:
   - 🟢 **OK** — well within interval (e.g. rotor has 200 hrs remaining)
   - 🟡 **Soon** — approaching interval (e.g. rotor has 50 hrs remaining)
   - 🔴 **Due / Overdue** — at or past interval (rotor needs attention now)

   The technician or manager decides whether to act on yellow/red items while
   the asset is already in the workshop. This is a convenience, not an
   automatic cascade.

4. **No auto-cascade.** The motor's 500-hr PM does not auto-create a rotor WO.
   Component PM rules remain the authoritative trigger for component maintenance.

### Car analogy (validated)

> You bring the car for an oil change at 10,000 km. The brake pads were
> replaced at 5,000 km — they still have 15,000 km before their next interval.
> The mechanic checks: "Pads look fine, no action needed." But if the pads
> were due at 12,000 km, the mechanic says: "Pads are getting close — want me
> to do them now while the car is here?"

The oil change (parent PM) does not trigger brake pad replacement (component
PM). But it creates an *inspection opportunity* — the mechanic can see what is
coming due and offer to bundle the work to avoid a second workshop visit.

### Implementation

- PM evaluation runs on **all** assets independently — parent and components
  each get their own MR if their rule fires.
- The parent WO detail screen queries child assets and shows their PM status
  (green/yellow/red) as read-only indicators.
- A "Create MR for component" action is available from the parent WO screen
  for any yellow/red component — this is a manual decision, not automatic.
- Component PM baseline is updated when the component's own WO is closed (not
  the parent's).

---

## Summary: Decided vs. Open

| Question | Status |
|---|---|
| Q1 — One WO, component status updated separately | ✅ Decided |
| Q2 — Component hours via derived calculation (Option A) | ✅ Decided |
| Q3 — Dedicated `asset_assembly_history` table | ✅ Decided |
| Q4 — Installed / Ready sub-statuses | ✅ Decided |
| Q5 — Parent + component PM run independently, cross-check at parent service | ✅ Decided |

## What's Agreed

- ✅ Assets can be composed of other assets (arbitrary depth).
- ✅ `parent_asset_id` FK + `asset_kind` enum on assets table.
- ✅ `asset_assembly_history` table for install/removal audit trail.
- ✅ One parent per component. Swap is explicit. Cycle prevention enforced.
- ✅ One WO per asset. Parent WO updates component statuses; removed component gets its own MR if needed.
- ✅ Active sub-statuses: Installed (in a parent) and Ready (spare, available).
- ✅ Only applicable to specific asset categories — not all assets.
- ✅ Components are full ATMS assets with their own MRs, WOs, readings, and history.
