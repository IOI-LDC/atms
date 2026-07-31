# Future Scope: Phase 2 and Phase 3

<!--
MAINTENANCE:
- When a future phase or feature is approved for implementation, move its actionable
  requirement to REQUIREMENTS.md and update README.md and ROADMAP.md with the active
  phase/next step.
- After implementation and verification, remove the item from active requirements,
  record its outcome in IMPLEMENTATION_HISTORY.md, and move its durable behavior
  into PRODUCT.md, ENGINEERING.md, API.md, FRONTEND.md, or OPERATIONS.md.
-->

This file records agreed direction only. Nothing here is implemented or part of
current ATMS acceptance criteria unless a change explicitly promotes it.

## Phase 2 — Advanced asset operations

- Asset assembly: parent/component relationships, install/remove/swap history,
  cycle prevention, component operating-hour derivation, and component-aware PM
  visibility.
- Asset Movement (AM): requester submits a movement request; Logistics approves
  and confirms arrival; AM becomes the formal location-workflow owner.
- Component-aware movement and any related child-count/cascade contract.
- Asset-tag QR presentation and the approved ERP consumption handoff boundary.

## Phase 3 — Store Management (SM)

- Store/order workflow, inventory balances, stock movement, virtual workshop
  store, and the ERP consumption write-back once its contract is confirmed.
- SM remains operational maintenance consumption scope; it is not a general ERP
  replacement, procurement platform, or financial warehouse system.

## Guardrails

- Do not add assembly, AM approval, inventory, stock, or write-back behavior as
  incidental ATMS work.
- Do not treat placeholder architecture as an implementation contract. Design and
  validate against the shared backend/database at the time the phase is opened.
- Preserve the Phase 1 scope boundaries in [PRODUCT.md](PRODUCT.md).
