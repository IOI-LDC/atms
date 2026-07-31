# Authoritative Sources

The written documentation under `docs/` is the authoritative source for this project.

Known screenshot conflicts:
- “Chain of Custody” should be interpreted only as asset location history. Logistics, gate passes, shipment documents, custody workflows, and transfer approvals are out of scope.
- Category-level PM rules are not part of the locked MVP unless explicitly added to the PRD.
- Any advanced governance, audit campaign, inventory wallet, labor tracking, or checklist-engine behaviour shown or implied visually is out of scope unless present in the written PRD.
- **WO Forms boundary:** The configurable WO Form is in scope (client-requested) but is limited to: boolean/numeric/text field types with optional display units, a single active form per FA subclass (`fa_subclass_code`), pre/post-maintenance value capture, snapshot-on-WO-create, and sync-to-latest. Mandatory photo checklists, pass/fail scoring, checklist versioning approvals, and checklist-based defect generation remain out of scope. See `docs/atms/01-product/WO_FORMS.md`.

Locked MVP boundaries:
- Labor tracking is excluded. Do not add technician hour logs, labor rates, labor costs, timesheets, or productivity reporting.
- PM Rules apply only to individual ATMS-managed assets. Category, asset-type, unit, package, and template-level rules are excluded.
- Work Order parts are operational usage records selected from the SM parts catalogue (parts are owned by Store Management; ERP-synced parts live in SM tables). ATMS does not own parts, stock, valuation, procurement, warehouse transactions, or parts costing — those belong to SM.
- Asset physical location and location history are owned by the AM (Asset Movement) subsystem. ATMS reads current location from AM tables for display only. AM handles movement requests: Requester submits → Logistics approves → Logistics confirms arrival → AM tables update. Logistics does not introduce gate passes, shipment documents, or custody workflows within ATMS.
