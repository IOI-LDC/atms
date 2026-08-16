# RQ1 — PM level marking during a work order (mini-spec)

**Status: draft for decision.** Phase 6 is gated on this document existing and
being agreed (🟠 D-022). Nothing here is built.

Resolves the deep dive the design left open: *"while they work" — mid-WO entry
vs close-time marking*, plus the cascade generalisation and the persistence
semantics (D4).

---

## 1. The finding that shrinks this phase

**Most of RQ1 is already built.** `POST /work-orders/{id}/close` accepts
`serviced_pm_assignment_id`, and `CloseWorkOrder` already:

- resets that assignment's date and reading baselines,
- cascades the reset down to every lower level on the same asset,
- cancels any pending PM request for it with `decision_type =
  'performed_under_repair'` — deliberately *not* `cancelled`, so compliance
  reporting does not read it as a skipped service.

That is the whole of "mark a PM as performed during a repair", and it has been
live since before this programme started. LDC's request adds two things to it,
and neither is the hard part it looks like.

**So Phase 6 is a delta, not a feature.** Scoping it as a fresh build would
almost certainly reimplement the cascade beside the existing one.

---

## 2. What is actually missing

### (a) Marking *during* the work, not only at close

Q5 settled that the team marks a level **during the work and at completion** —
today the only moment is close, which is Admin/Manager-only. A technician who
performs an L2 while the asset is stripped down has nowhere to record it, and by
close time the person who knows is not the person closing.

### (b) The cascade is capped at L4 by accident

[`CloseWorkOrder::resetLowerLevelAssignments`](../../backend/app/Actions/WorkOrders/CloseWorkOrder.php)
matches `/^L([1-4])$/`. `pm_rules.maintenance_level` is a free `varchar(10)` and
the rule form already offers a **custom** level, so:

- an `L5` rule would silently not cascade, and would silently not *be* cascaded
  into;
- a custom level (`SEASONAL`, say) is skipped entirely.

The L4 bound is arbitrary. The custom-level skip is not — you cannot order
`SEASONAL` against `L2` — but today the two failures are indistinguishable, and
only one of them is intentional.

### (c) The close warning can finally say what it means

4b ships a warning when an asset flagged **Need Inspection** is closed. The
design wants it narrower: *warn when the asset needed inspection **and no PM was
marked***. The second half is unknowable until this phase exists. Once it does,
the warning stops firing on the common case where the inspection was done and
recorded properly.

---

## 3. Proposed design

### 3.1 Persistence — staged, applied at close (D4)

A mark entered mid-WO is **recorded immediately but takes effect at close**, and
is **discarded on cancel**.

The alternative — applying immediately — advances the PM schedule the moment
someone ticks a box. If the work order is then cancelled, the asset's next
service has been silently pushed out by a full interval and nothing shows why.
Staging costs one small table; getting it wrong costs a missed service.

```
work_order_pm_marks
  id                        bigserial
  work_order_id             FK → work_orders, UNIQUE     ← at most one mark per WO
  asset_pm_assignment_id    FK → asset_pm_assignments
  marked_by_user_id         FK → users
  marked_at                 timestamp
  created_at, updated_at
```

**`work_order_id` is UNIQUE.** The design calls for a *single "highest level
performed" picker, not a multi-select* — the ladder is cumulative, so marking L3
already means L1 and L2. Re-marking replaces the row rather than adding one, and
the unique index is also the idempotency key: a double-submit is a no-op.

### 3.2 API

| Method | Route | Notes |
|---|---|---|
| `PUT` | `/work-orders/{workOrder}/pm-mark` | Body `{ asset_pm_assignment_id }`. Idempotent — replaces any existing mark. |
| `DELETE` | `/work-orders/{workOrder}/pm-mark` | Clears the mark ("actually, we didn't"). |

`PUT`, not `POST`: the operation is "set the mark for this work order", and
running it twice must not produce two marks.

**Authorization: `updateExecution`.** The existing precedent for parts, form
fields and readings — the assigned technician while the WO is open or in
progress, Admin/Manager until it closes. That is exactly right here: the
technician records what they did; the manager can still correct it before close.

**Guards** (reuse `CloseWorkOrder`'s existing ones rather than restating them):

- the assignment must belong to this work order's asset — **409** otherwise;
- the assignment must be active — **409**;
- the work order must be open or in progress — **409** (the policy covers
  closed/cancelled; this covers `completed` for a technician).

### 3.3 Applying it at close

`CloseWorkOrder` resolves the effective assignment as:

```
serviced_pm_assignment_id (close payload)   ← wins if present
  else work_order_pm_marks.asset_pm_assignment_id
  else none
```

Everything downstream is unchanged — the existing reset, cascade and
PM-request-suppression path runs exactly as it does today.

**When the two disagree, the close payload wins and the discrepancy is
audited.** It is the later and more deliberate act, and the house rule is that a
paperwork conflict must never block an operational transition. A 409 here would
strand a work order over a disagreement about which of two correct-looking
levels was performed.

**If the staged assignment was deactivated between marking and close**, the mark
is skipped, audited as `work_order_pm_mark.skipped`, and surfaced in the close
`warnings` array (the mechanism 4b already added). Same principle as
`ConfirmWorkOrderReadings`: skip, audit, never block.

### 3.4 Cancel

The mark is deleted and the deletion audited. Nothing else happens — the
schedule is untouched, which is the entire point of staging.

### 3.5 Generic cascade

Replace `/^L([1-4])$/` with `/^L(\d+)$/` in both places, and state the rule that
is currently implicit:

> Levels of the form `L<number>` are ordered and cascade. Any other level —
> including the rule form's **custom** option — participates in no cascade,
> because there is no defined ordering between `SEASONAL` and `L2`.

The UI must say this where a custom-level assignment appears in the picker,
otherwise marking one looks like it did nothing.

### 3.6 Audit events

| Event | When |
|---|---|
| `work_order_pm_mark.set` | Mark created or replaced (metadata carries the previous assignment id) |
| `work_order_pm_mark.cleared` | Mark deleted by a user |
| `work_order_pm_mark.discarded` | Work order cancelled with a mark staged |
| `work_order_pm_mark.skipped` | Staged assignment no longer usable at close |
| `work_order_pm_mark.superseded` | Close payload carried a different assignment id |

### 3.7 Frontend

- **Work order detail, execution section** — a "PM performed" picker beside the
  parts and readings controls, listing the asset's active assignments labelled by
  level, plus a "None" option. Visible while the WO is open or in progress.
- **Close dialog** — shows the staged mark as pre-selected in the existing
  "a service was also performed" control rather than adding a second control.
  The two are the same decision at different moments.
- **Close warning** narrows to *Need Inspection **and** no PM marked*.

---

## 4. Decisions I need from you

These are the gate. I have a recommendation for each and will proceed on them if
you'd rather not litigate every one.

1. **Staged, or applied immediately?** *Recommend staged* (§3.1). This is D4 and
   it is the only genuinely load-bearing choice here.
2. **One mark per work order, or several?** *Recommend one* — the ladder is
   cumulative, so a second mark can only be redundant or contradictory.
3. **Close payload vs staged mark on conflict.** *Recommend the payload wins,
   audited* (§3.3).
4. **Can a technician clear a mark after marking it?** *Recommend yes*, same
   permission as setting it. The alternative traps a mistake until a manager is
   found.
5. **Does a custom-level mark warrant a warning at close?** *Recommend no* — it
   is a legitimate thing to record; it just cascades to nothing, and the UI says
   so at the point of choosing.

---

## 5. Tests this phase must carry

- Mark → close → the assignment's baselines reset **and** every lower numeric
  level cascades; a custom level does not.
- Mark → **cancel** → schedule untouched, mark gone, discard audited. *The test
  that justifies the whole staging model.*
- Mark → assignment deactivated → close → skipped, audited, warning returned,
  close still succeeds.
- Close payload overrides a staged mark; supersession audited.
- `PUT` twice with the same body → one row, no duplicate audit noise.
- `PUT` with an assignment belonging to another asset → 409.
- Technician on their own WO may mark; a technician on someone else's may not;
  neither may mark a closed WO.
- `L5`/`L10` cascade correctly — the regression the current L4 bound would cause.

---

## 6. Explicitly not in this phase

- **RQ2's attachment gate.** The design names the inspection form as the
  attachment carrier for this mark, but whether an attachment is *required* is
  D5, and Phase 5 owns it. This phase must not grow a file upload.
- **Reworking PM evaluation.** `PmEvaluationRunner` is untouched; this phase only
  changes what a close writes to the baselines.
- **A level-ordering UI.** Levels stay a free string on the rule. Introducing a
  managed level vocabulary is a larger change and nothing here needs it.
