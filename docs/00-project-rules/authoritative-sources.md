# Authoritative Sources

The written documentation under `docs/` is the authoritative source for this project.

Screenshots under `amts-screenshots/` are visual references only. They may be used for UI layout, spacing, general page structure, and visual direction, but they must not introduce new scope, workflows, entities, permissions, or backend behaviour.

If a screenshot conflicts with the written documentation, the written documentation wins.

Known screenshot conflicts:
- “Chain of Custody” should be interpreted only as asset location history. Logistics, gate passes, shipment documents, custody workflows, and transfer approvals are out of scope.
- Category-level PM rules are not part of the locked MVP unless explicitly added to the PRD.
- Any advanced governance, audit campaign, inventory wallet, labor tracking, or checklist-engine behaviour shown or implied visually is out of scope unless present in the written PRD.

Locked MVP boundaries:
- Labor tracking is excluded. Do not add technician hour logs, labor rates, labor costs, timesheets, or productivity reporting.
- PM Rules apply only to individual ERP-linked assets. Category, asset-type, unit, package, and template-level rules are excluded.
- Work Order parts are operational usage records selected from ERP-linked reference data. Do not add stock quantities, valuation, procurement, warehouse transactions, or parts costing.
- Logistics is a fixed role for asset physical location updates and location history only. It does not introduce logistics documents, approvals, handovers, or custody workflows.
