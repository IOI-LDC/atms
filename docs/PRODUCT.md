# ATMS Product and Workflow Summary

## Purpose and scope

ATMS manages operational asset maintenance: assets, maintenance requests (MRs),
work orders (WOs), preventive-maintenance (PM) rules, meter readings, attachments,
locations, bookings, dashboard metrics, operational reports, and administration.

Assets are operated in ATMS today. Parts are exposed through the current parts
catalogue and ERP-sync path; inventory, purchasing, costing, stock movement, and
warehouse execution are not ATMS responsibilities. Current location updates are
direct operational actions. A formal movement-request workflow is future AM scope.

## Core workflow

```text
Corrective: requester/technician/admin/manager creates MR
            -> manager/admin approves or rejects
            -> approval creates WO
            -> assign -> start -> complete -> close

Preventive:  active per-asset PM assignment becomes due
             -> scheduler or explicit evaluation creates MR
             -> same approval-to-WO flow
```

MR statuses are `pending_review`, `rejected`, `converted`, and `cancelled`.
WO statuses are `open`, `in_progress`, `completed`, `closed`, and `cancelled`.
Closed WOs are immutable. Only Administrators and Maintenance Managers may close
or cancel WOs. A requester may cancel only their own pending corrective MR.

## Roles

| Role | Primary responsibility |
|---|---|
| Administrator | System configuration, users, master data, PM templates, audit access, and full operational administration. |
| Maintenance Manager | Reviews MRs, manages PM assignments, assigns and closes WOs, and oversees maintenance data. |
| Technician | Creates corrective MRs, performs assigned WOs, records permitted readings and execution information. |
| Logistics | Updates asset locations; has operational visibility but no MR/WO approval authority. |
| Requester | Creates and tracks their corrective MRs; has limited operational visibility. |

The service role is machine-to-machine only. Exact authorization is enforced by
Laravel policies; do not infer permission from a screen alone.

## Permission and visibility model

| Capability | Administrator | Maintenance Manager | Technician | Logistics | Requester |
|---|---:|---:|---:|---:|---:|
| View active assets and operational records | Yes | Yes | Yes | Yes | Yes |
| Create or edit assets | Yes | Yes | No | No | No |
| Direct location update / booking | Yes | Yes | No | Yes | No |
| Create corrective MR | Yes | Yes | Yes | No | Yes |
| Approve or reject MR | Yes | Yes | No | No | No |
| Assign, close, or cancel WO | Yes | Yes | No | No | No |
| Start or complete an assigned WO | Yes | Yes | Assigned only | No | No |
| Manage PM templates | Yes | View/assign/evaluate | No | No | No |
| Manage WO-form templates | Yes | No | No | No | No |
| Admin users, locations, lists, audit logs, settings | Yes | Limited user visibility | No | No | No |

The table is an orientation aid. Route-specific checks, record visibility, and
machine-token access must be read from the relevant Laravel policy before changing
an authorization rule.

## Important business rules

- PM templates are created and maintained by Administrators. Administrators and
  Maintenance Managers assign, deactivate, reactivate, and evaluate templates for
  individual assets. Category/type auto-application is not supported.
- PM triggers are `date`, `reading`, or `date_or_reading`. Confirmed readings are
  the only readings used by reading-based PM calculations. **New rules can only be
  created as `date`** — reading-based triggers are parked in the UI until the LDC
  Job Management system feeds per-job asset usage into ATMS. Existing
  `date_or_reading` rules keep running on their date dimension.
- Meter confirmations reject values lower than, or dated earlier than, the last
  confirmed value. When confirmation runs as part of closing a work order, such a
  reading is **skipped and audited**, never fatal — a data-quality problem must not
  block an operational transition. A future reset, if approved, must be a separate
  audited workflow.
- Assets use `enrolled` or `withdrawn` maintenance status. Operational status is
  distinct; do not conflate either with booking state.
- Booking is independent of maintenance workflow. A location change or asset
  inactivation releases a booking.
- Attachments use persistent application storage. Access and deletion are policy
  controlled and every operational change is auditable.
- WO forms are implemented: an Administrator manages templates by **Maintenance
  Category**; a WO snapshots its form, supports an explicit sync/defer decision,
  and cannot complete until all required fields are filled.
- **Maintenance Category is the routing key.** It is the only asset
  classification ATMS owns, so it is what selects a WO form and what a PM rule
  may cover. `fa_subclass_code` is written by the ERP sync: ATMS may describe an
  asset with it (reports, asset tags) but never route behaviour by it.
- Every asset carries a Maintenance Category. An asset nobody has classified sits
  in the **Unclassified** category — a real, countable, filterable value rather
  than a blank — so unclassified assets can be found and cleared rather than
  quietly falling outside every rule.

## Workflow detail

### Corrective maintenance

Any permitted user creates a corrective MR with the affected asset, priority, and
problem description. While it is `pending_review`, an Administrator or Maintenance
Manager may update it; the creator of a corrective request may update their own
request when policy allows. Approval atomically creates the WO and carries the MR
priority forward. Rejection requires a reason. A pending corrective request may be
cancelled only by its owner where permitted or by an Administrator/Manager.

The WO is assigned before work starts. The assigned Technician, Administrator, or
Maintenance Manager records execution data, permitted parts, readings, asset status,
attachments, and WO-form values. A completed WO is reviewed and closed by an
Administrator or Maintenance Manager. Closing updates the derived maintenance
history; it does not create a second editable history record.

Closing also **confirms the meter readings taken on that work order**, records the
asset's meter position per reading type as an immutable snapshot, and — where the
closer declares it — registers a preventive service performed alongside the job.
That last case covers the repair shop: the asset was in for a repair, a service
level happened to be due, and the team did both; the declaration resets that
schedule's baselines and retires the PM request already raised for it.

### Preventive maintenance

A PM rule is a reusable template. It has no operational effect until assigned to a
specific asset. Each assignment owns its own baseline. Scheduled or manual
evaluation creates at most one active maintenance chain for that assignment.

A rule may also cover one or more **Maintenance Categories**. Coverage is a
statement of intent that is expanded into one ordinary assignment per member
asset, not a rule evaluated on the fly — each asset needs its own baseline, and a
newly covered asset is given a full interval of grace before its first PM. The
expansion keeps itself in step as assets are created, change category, or leave
the maintenance program.

Coverage may create and withdraw its own assignments; it may never overrule a
person. An assignment made deliberately for one asset survives every category
change, and an assignment a person switched off is never switched back on by
coverage. An assignment with an open request or work order is left alone until
that chain finishes.

For a due PM occurrence, rejection or cancellation records suppression boundaries
so the scheduler does not recreate the same occurrence. Date-triggered, reading-
triggered, and `date_or_reading` rules require the matching suppression dimensions.
A suppression written because the service was actually performed under another work
order carries `performed_under_repair` rather than `cancelled`, so compliance
reporting does not count a completed service as a skipped one.

PM baseline updates occur on WO closure using the assignment, not by altering the
template globally. That is normally the closure of the preventive WO the occurrence
generated — but a **repair** WO can also reset a baseline when the closer declares
the service was performed during it.

### Readings, locations, bookings, and forms

- **Nobody confirms readings by hand.** A reading is recorded unverified and becomes
  confirmed when the work order it was taken on is closed. Closing is an
  Administrator/Maintenance Manager action and the technician who took the reading
  cannot do it, so the close is the second pair of eyes — a verify button clicked by
  the person who entered the value would not be. Readings never attached to a work
  order that closes — from a rejected or cancelled request, or a cancelled WO —
  stay unverified permanently, and that is intended.
- Confirmed readings are append-only and monotonically non-decreasing per asset and
  reading type. A valid correction is a new reading, not editing history.
- Phase 1 location update is a direct action that writes a location-history row.
  It has no movement request, arrival confirmation, gate-pass, or custody flow.
- Booking reserves an asset for a job/project over a date range (`booked_from` /
  `booked_until`) with an optional job reference and the identity of who committed
  the booking. Bookings live in a dedicated `bookings` table so full history is
  preserved (active → cancelled / released). An asset's `is_booked` state is
  derived: it is booked when an active booking covers today. Overlapping bookings
  on the same asset are rejected. Booking is released automatically by asset
  inactivation or withdrawal from the maintenance program. It does not replace
  maintenance status.
- An active WO-form template is selected by the asset's Maintenance Category. A
  template may serve several categories, but at most one *active* template may
  serve any one category — that is what makes an asset's form unambiguous, since
  an asset carries exactly one category. A WO snapshots it at
  creation; syncing newer template changes is explicit and may be deferred. Fields
  with `has_pre_post` require both values; other required fields require their post
  value before the WO can complete.
- A required field a technician never touched blocks completion, and a required
  boolean is no exception — an unanswered check is not an implicit "No" (decided
  2026-07-28). Only an explicit answer clears the gate, so a closed record never
  reads as though a check was deliberately answered when nobody looked at it.

### User provisioning

Users are created directly by an Administrator, not sourced from an external
employee directory. The original plan to connect to the LDC SharePoint employee
list was set aside: the SharePoint transport was never implemented and the CSV
export is a development aid, not a production source.

To create a user, the Administrator enters the person's name, email, and role.
The email must belong to the `@ldc.com.ly` domain (case-insensitive, with an
allowlist config for exceptional domains). The system creates the account with
a random password and `is_active: false`, then sends an activation email to the
given address. The recipient proves mailbox ownership by clicking the activation
link and setting their own password — that is the verification that the person
is an LDC employee with a valid LDC mailbox. No directory sync, import, or
SharePoint integration is required.

Once activated, the Administrator can edit the user's name/email/role, reset
their password, or deactivate/reactivate the account. An Administrator cannot
perform these actions on their own account.

### Where asset data comes from

ATMS is currently the **operational source** for asset reference data. Assets
were loaded once from an ERP CSV extract (`atms:import-erp-assets`) and are
maintained in ATMS thereafter.

**A weekly ERP sync covering both assets and parts is planned for Phase 3**
(roughly six months out as of 2026-08-17, subject to LDC budget). Parts already
sync weekly; assets do not.

The practical consequence today: **when an asset is disposed or otherwise
deactivated in the ERP, ATMS is not told.** Someone has to deactivate it in
ATMS, either by re-running the import or by switching it off in the Admin UI.
Until the sync lands, that is a human procedure and belongs in LDC's operating
instructions, not in the software.

**"Inactive" in the ERP is permanent** (confirmed with LDC 2026-08-17). It means
disposed, lost in hole, damaged beyond repair, or sold — the asset has been
written off in the financial records and is not coming back. It never means
"temporarily away": an asset at a vendor for repair, in transit, or awaiting
parts stays active in the ERP. That makes the mapping into ATMS a clean one —
ERP inactive → `is_active = false` — with no risk of ejecting equipment that is
merely out of sight for a fortnight.

Deactivating an asset (`is_active = false`) removes it from every workflow —
no maintenance requests, no work orders, no preventive-maintenance evaluation,
no location changes, no bookings (existing ones are released automatically) —
and hides it from every role except Administrator and Maintenance Manager.

**A deactivated asset is never deleted from ATMS. Not after six months, not
ever** (LDC policy, 2026-08-17). An asset that has been in service carries
closed work orders, meter readings, parts consumption and failure records, and
those are precisely what ATMS exists to hold — the ERP has the financial history,
ATMS has the maintenance one. Deleting a written-off asset would rewrite past
MTBF, failure analysis and parts-usage figures, changing the answer to questions
about periods that are already closed.

> The `atms:import-assets --prune` flag does delete assets, cascading to their
> maintenance records. That is a **pre-handover data-reset tool**, not an
> operational one, and it should not be used once LDC's real history begins to
> accumulate.

### Operational status

Every asset carries an `operational_status` answering "is the asset working
right now?" — distinct from maintenance status and booking state. Since release
4b (2026-08-16) there are **four** values:

| DB value | Label |
|---|---|
| `ready_for_field` | Ready for Field |
| `under_maintenance` | Under Maintenance |
| `failure` | Failure |
| `at_the_field` | At the Field |

`failure` was renamed from `down`: LDC read "down" as *waiting for parts*, which
is a cause rather than an operational state. `at_the_field` is **derived from
location** — it is written when an asset moves somewhere classified as deployed
(rig or well site) and cleared when it returns, and it is deliberately absent
from every manual picker.

The former `scraped`, `under_inspection` and `lih` values were removed. An asset
that has left the fleet is **deactivated** (`is_active = false`), which is now
the single "out of ATMS" control; an inspection is a PM, not a separate state.
Distinguishing *scrapped* from *lost in hole* for reporting would need a new
withdrawal-reason field and is not recoverable from asset data today.

Operational status is driven primarily by Work Order events: approving a
corrective MR sets `failure`, starting work sets `under_maintenance`, and
**closing always returns the asset to `ready_for_field`**. A job that did not
restore the asset is cancelled rather than closed — cancel keeps a caller's
choice of `failure` or `ready_for_field`. A deactivated asset is never touched by
any lifecycle transition.

Manual location moves are gated: a `ready_for_field` asset moves anywhere, an
`at_the_field` asset may only return to a yard or building, and a `failure` or
`under_maintenance` asset cannot be moved by hand at all — its work order decides
where it goes. Workflow-driven moves (work-order start, MR approval) bypass the
gate.

### Condition

A second, independent axis answering *what is wrong with this asset?* — an
Admin-editable vocabulary (`assets.condition_status`, master-data group
`asset_conditions`), seeded with **Normal** (the default), **Need Assembly**,
**Missing Parts** and **Need Inspection**.

An asset can be Ready for Field with a condition of Missing Parts: serviceable,
but not complete. Returning from the field stamps **Need Inspection**
automatically; closing a work order resets the condition to the default, and
warns if the asset had been flagged for inspection. Cancelling never resets it —
a cancelled job fixed nothing.

**Phase 1.5 (third-party inspection certificates) is cancelled (2026-08-16)**:
for LDC, "inspection" means PM — an inspection form attached to the WO with the
executed PM marked (see
`docs/plans/2026-08-07-operational-status-vocabulary.md` §7 RQ1/RQ2). A
recurring inspection is modelled as an ordinary date-based PM rule, with its
completed form or supporting evidence uploaded as an attachment on the work
order that closes it.

### Meter readings

Meter readings are absolute, cumulative totals — monotonically non-decreasing
per asset and reading type once confirmed. On the WO "Record meter reading"
form, the operator enters the **delta**: the amount the meter has operated since
the last recorded reading (e.g. 50 hours). The **Total** (current meter reading)
is auto-calculated as `last reading + delta` and shown read-only; the stored
`reading_value` is that absolute total, so history and PM calculations keep
working with absolute values. A negative delta is rejected.

**The entered delta is stored too**, as `entered_delta`. Without it a wrong total
could not be traced to a mistyped delta rather than a bad base, and a technician
who only knows what the meter has moved could not correct their own entry. It is
informational — nothing in PM evaluation, the monotonicity guards, or reporting
reads it. A reading entered as a delta is **edited as that same delta**, with the
total recomputed from the reading immediately before it in its own series; a
reading entered as an absolute is edited as an absolute, which clears any stale
delta.

⚠️ **With no prior reading for the type, the entered delta becomes the total.** An
operator typing "50" meaning fifty hours since last service seeds a lifetime meter
value of 50. The failure is silent — the reading dimension simply never reaches its
threshold — and it must be fixed before reading-based PM is unparked. Tracked as
D-018.

## Notifications

ATMS notifies by email only. There is no in-app notification centre, no digest, and
no per-user subscription or opt-out. Delivery is queued and serialized; a send
failure is retried and never blocks or reverses the workflow transition that
triggered it. Two families share one transport and one template: **account emails**
(user activation, password reset) and **workflow emails** (MR and WO transitions).

| Trigger | To | Cc |
|---|---|---|
| Corrective MR submitted | All active Maintenance Managers | — |
| MR approved, creating the WO | Requester, plus the assigned Technician if one was named | — |
| MR rejected | Requester | — |
| WO assigned | Assigned Technician | — |
| WO started | All active Maintenance Managers | — |
| WO completed | All active Maintenance Managers | — |
| WO closed | Assigned Technician | All active Maintenance Managers |
| WO cancelled | Assigned Technician | — |

Rules that hold for every workflow email:

- Only active users are addressed. A recipient without an email address is skipped,
  and an empty recipient set sends nothing rather than failing the transition.
- Preventive MRs created by scheduled PM evaluation do not notify anyone. This is a
  decision, not an omission: one evaluation run can create many requests, and Managers
  see due PM work on the dashboard and MR list. Only the corrective create path emails
  Managers.
- Each message states the record, asset, and the next expected action, and carries a
  deep link to that record. The link host comes from application configuration.
- Recipients are resolved by role at send time, not from a stored distribution list.

## Reporting

Reports are authenticated, read-only, organisation-wide operational views. They do
not introduce a data warehouse, forecasting, financial analysis, Power BI, or a
custom report builder. The active endpoint catalogue is in [API.md](API.md).

## Explicit exclusions

- Labor hours, rates, costs, productivity, timesheets, or technician wallets.
- Procurement, purchasing, inventory valuation, warehouse transactions, and parts
  costing.
- Financial fixed-asset management or ERP-led financial lifecycle.
- Gate passes, shipment documents, custody workflows, and a general document
  management system.
- Advanced checklist scoring, approval/versioning, photo-checklist requirements,
  and defect-generation engines.

Future scope is deliberately separated in [FUTURE_SCOPE.md](FUTURE_SCOPE.md).
