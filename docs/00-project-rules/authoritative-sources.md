# Authoritative Sources

The written documentation under `docs/` is the authoritative source for this project.

Screenshots under `amts-screenshots/` are visual references only. They may be used for UI layout, spacing, general page structure, and visual direction, but they must not introduce new scope, workflows, entities, permissions, or backend behaviour.

If a screenshot conflicts with the written documentation, the written documentation wins.

Known screenshot conflicts:
- “Chain of Custody” should be interpreted only as asset location history. Logistics, gate passes, shipment documents, custody workflows, and transfer approvals are out of scope.
- Category-level PM rules are not part of the locked MVP unless explicitly added to the PRD.
- Any advanced governance, audit campaign, inventory wallet, labor tracking, or checklist-engine behaviour shown or implied visually is out of scope unless present in the written PRD.