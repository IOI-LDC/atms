# Milestones

## Milestone 1 — Foundation Ready

Backend and frontend skeletons are running in Docker with authentication and PostgreSQL.

## Milestone 2 — ERP Reference Data Ready

Fixed assets and parts can be synced from ERP into local operational tables, with sync history and errors.

## Milestone 3 — Asset Registry Ready

Users can search assets, view asset details, add usage readings, update location, view history, and manage attachments.

## Milestone 4 — Maintenance Request Workflow Ready

Users can create Corrective Maintenance Requests. Maintenance Manager can approve/reject requests. Approval creates Work Order.

## Milestone 5 — Work Order Workflow Ready

Users can view active Work Orders, update notes, record parts used, add attachments, and close Work Orders.

## Milestone 5a — WO Execution Forms Ready

WO Form templates are configurable by Admin per FA subclass with boolean/numeric/text fields, pre/post value capture, snapshot-on-WO-create, sync-to-latest, and completion gate enforcement.

## Milestone 6 — Preventive Maintenance Ready

Admins define reusable PM templates; templates are assigned to assets (Admin/Manager). The system evaluates active assignments and generates Preventive Maintenance Requests when due.

## Milestone 7 — Dashboard and UAT Ready

Dashboard and basic reporting are complete. System is ready for client testing.

## Milestone 8 — Production Deployment

System is deployed on VPS using Docker Compose and handed over for production use.
