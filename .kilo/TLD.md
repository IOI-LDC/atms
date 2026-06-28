# Task List — Development Tracker

> **Purpose:** Single source of truth for what is being built, what was completed
> that the other team needs to know about, and what was deferred so it doesn't
> get lost.
>
> **Update this during or immediately after every build session.** It is short
> by design — if a task takes more than 2 minutes to write down, it is too big
> and should be split.

---

## 🔴 In Progress

<!-- What someone is actively coding right now. Max 2 items per team. -->

| Team | Task | Started |
|---|---|---|
| _(none active)_ | | |

---

## 🟡 Recently Completed — Frontend / Other Teams Need to Know

<!-- Backend finished something? Put it here so frontend knows the API changed.
     Frontend finished a screen? Put it here so others know the UX is done.
     Items stay here until the other team acknowledges. Then move to Done. -->

| Date | What changed | Who needs to know | Status |
|---|---|---|---|
| 2026-06-28 | VPS Frontend Issue Tracker created (`docs/atms/04-frontend/VPS_FRONTEND_ISSUES.md`) — 8 issues logged (5 MR, 3 WO, 1 Asset). 4 frontend fixes verified on VPS, 3 need backend follow-up. | Frontend, Backend | ⚠️ Unacknowledged |
| 2026-06-28 | Power Automate Notification Integration spec (`docs/03-backend/NOTIFICATIONS.md`) — 5 triggers, payload contracts, Laravel implementation, setup checklist. | Frontend (notification UX), Backend (queued jobs) | ⚠️ Unacknowledged |
| 2026-06-28 | WO assignable roles expanded — Admin/Manager can assign WO to Technician OR Maintenance Manager (small teams, overloaded tech). | Frontend (assign picker), Backend (policy) | ⚠️ Unacknowledged |
| 2026-06-24 | ERP connection confirmed (Entra ID → BC OData V4). Token + assets working. Env vars in `backend/.env`. | Frontend (API base URL pattern changed), SM team | ⚠️ Unacknowledged |
| 2026-06-24 | Asset Assembly model decided. 5 API endpoints defined: `install`, `remove`, `swap`, `assembly-history`, `children`. | Frontend (new routes), Backend (new actions) | ⚠️ Unacknowledged |
| 2026-06-24 | Asset tag format `L-BBB-CCC-XXXX` decided. New `asset_tag` column added to spec. | Frontend (create/edit forms, QR), Backend (migration, validation) | ⚠️ Unacknowledged |
| 2026-06-24 | Mock ERP deprecated. Real ERP auth + URL pattern documented in `ERP_SYNC.md`. | Backend (4 PHP files to clean up) | ⚠️ Unacknowledged |

---

## 🟠 Deferred — Do Not Forget

<!-- Agreed changes that were postponed. Must include a TRIGGER — when to
     bring it back. Without a trigger, it will be forgotten. -->

| ID | What | Reason deferred | Trigger to revisit |
|---|---|---|---|
| D-001 | Rename `frontend/` → `atms/` + update Docker/nginx configs | Docs restructure done; code rename deferred per plan | When backend team starts SM subsystem |
| D-002 | Update `CLAUDE.md` to match new docs structure | Explicitly out of scope for docs restructure | After `frontend/` → `atms/` rename |
| D-003 | `asset_tag` QR code generation on asset detail page | Tag format decided; QR is future scope | After asset_tag column is implemented and populated |
| D-004 ✅ | Virtual Store resolved — one workshop, per-part approval, auto-flag, overnight hold with next-day enforcement | 2026-06-24 | Done — spec in `docs/sm/01-product/VIRTUAL_STORE.md` |
| D-005 | SM architecture + parts catalogue design | VJ reply pending. If BC Store Order live → no local `parts` table needed; query BC directly. If not → build `parts` table synced from ERP. `work_order_parts` table needed either way. | When VJ replies about BC Store Order |
| D-006 | Parts consumption write-back to ERP (SM → ERP when stock issued at GR) | Under discussion with LDC; not yet agreed. ERP team must confirm API contract for consumption/decrement transaction. | After LDC meeting on parts write-back |

---

## 🟢 Done

<!-- Move items here once all teams have acknowledged. Keep last 10. -->

| Date | What | Completed by |
|---|---|---|
| 2026-06-28 | VPS frontend bug tracker + notification integration spec + docs README updated | AI-assisted |
| 2026-06-24 | Documentation restructure (3 subsystems, 5 roles, 19 files updated) | AI-assisted |
| 2026-06-24 | ERP connection tested: token acquired, 429 assets fetched from BC | AI-assisted |
| 2026-06-24 | Mock ERP env vars removed from compose.yaml, .env, backend/.env | AI-assisted |
| 2026-06-24 | `RISKS_AND_ASSUMPTIONS.md` updated with real ERP details | AI-assisted |
| 2026-06-24 | LDC meeting prep document (parts write-back) + PDF | AI-assisted |
| 2026-06-25 | Sidebar navigation redesign (flat + tabs, 7 items, 5 docs rewritten) + Secure Remote API Access spec (new doc, 3 docs updated) | AI-assisted |

---

## 🔵 External Blockers

<!-- Things we cannot advance until someone outside the team provides
     something. Full details in docs/05-delivery/TDL.md. -->

| # | What | Waiting on |
|---|---|---|
| 1 | Parts API page name (BC custom API page) | ERP team / LDC |
| 2 | Parts field mapping (response schema) | ERP team / LDC |
| 3 | `componentOfMainAsset` sample with non-null parent | ERP team / LDC |
| 4 | Power Automate webhook URL for notification testing | LDC IT / Power Automate admin |

---

## Process

1. **Start of build session:** Read this file. Pick up anything in 🔴 In Progress
   or pull from 🟠 Deferred if its trigger fired.
2. **During build:** If a requirement surfaces that needs the other team, add it
   to 🟡 immediately — do not assume you will remember.
3. **When postponing:** Add to 🟠 Deferred with a clear trigger. "Later" is not
   a trigger. "After asset_tag migration is merged" is a trigger.
4. **End of session:** Move completed items to 🟢 Done. Move 🟡 items that were
   acknowledged. Review 🟠 for any triggers that fired.

## Phase 2 (deferred)

| P2-001 | Asset Assembly: parent_asset_id column, install/remove/swap Actions, assembly_history table | Phase 2 |
| P2-002 | Component PM cross-check: 🟢🟡🔴 indicators + "Create MR for Component" | Phase 2 |
| P2-003 | SM Order workflow: Order → Approval → Dispatch → Goods Receipt | Phase 2 |
| P2-004 | SM inventory management: stock movement, balances, Virtual Store | Phase 2 |
| P2-005 | AM movement workflow: Requester → Logistics approve → confirm arrival | Phase 2 |
| P2-006 | ERP parts write-back from SM GR to BC ERP | Phase 2 |
| P2-007 | SM full build vs. BC Store Order integration (pending VJ reply) | Phase 2 |
