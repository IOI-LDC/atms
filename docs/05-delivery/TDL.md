# Task Delivery List

## Foundation

- [x] Create Laravel backend project (Task 1)
- [ ] Create Vue frontend project
- [x] Configure Docker Compose (Task 1)
- [x] Configure PostgreSQL (Task 1)
- [x] Configure environment files (Task 1)
- [x] Implement authentication (Task 3)
- [x] Implement base RBAC (Task 4)

## Administration

- [ ] Users list
- [ ] User create/edit
- [x] Roles setup (Task 4)
- [x] Locations CRUD (Task 8)
- [x] Master data CRUD (Task 8)
- [ ] ERP sync settings

## ERP Sync

- [x] Create ERP sync job tables (Task 7)
- [x] Implement asset sync job (Task 7)
- [x] Implement parts sync job (Task 7)
- [x] Implement manual sync endpoint (Task 7)
- [ ] Implement sync history UI
- [ ] Implement sync error UI

## Assets

- [x] Asset list API (Task 14 — role-scoped Resource, cursor pagination, filtering, sorting)
- [x] Asset detail API (Task 14 — role-scoped Resource)
- [ ] Asset list UI
- [ ] Asset detail UI
- [x] Meter reading API (Task 8)
- [ ] Meter reading UI
- [x] Location update API (Task 8)
- [ ] Location history UI
- [x] Asset attachments API (Task 12)
- [x] Asset maintenance history API (Task 14 — derived from closed WOs)
- [ ] Asset maintenance history UI

## Parts

- [x] Parts list API (Task 14 — role-scoped Resource, cursor pagination, filtering, sorting)
- [x] Part detail API (Task 14 — role-scoped Resource)
- [ ] Parts list UI
- [ ] Part detail UI
- [x] Part attachments API (Task 12)

## Maintenance Requests

- [x] Corrective MR create API (Task 9)
- [ ] Corrective MR form UI
- [x] MR list API (Task 14 — role-scoped Resource, cursor pagination, filtering, sorting)
- [ ] MR list UI
- [x] Review MR API (Task 9)
- [ ] Review MR UI
- [x] Approve & create WO action (Task 9)
- [x] Reject MR action (Task 9)

## Work Orders

- [x] WO list API (Task 14 — role-scoped Resource, cursor pagination, filtering, sorting)
- [x] WO detail API (Task 14 — role-scoped Resource)
- [ ] WO list UI
- [ ] WO detail UI
- [x] Parts used API (Task 10)
- [ ] Parts used UI
- [x] WO attachments API (Task 12)
- [x] Close WO API (Task 10)
- [ ] Close WO UI
- [ ] Closed WO history UI

## PM Rules

- [x] PM rule CRUD API (Task 14 — role-scoped Resource, cursor pagination, filtering, sorting)
- [ ] PM rule UI
- [x] PM evaluation service (Task 11)
- [x] PM scheduler job (Task 11)
- [x] Preventive MR generation (Task 11)
- [x] PM baseline update after WO closure (Task 11)

## Audit System

- [x] Append-Only Technical Audit (Task 13)

## Dashboard

- [x] Dashboard summary API (Task 14 — role-adaptive widgets)
- [ ] Dashboard UI cards
- [x] Pending MR widget (Task 14)
- [x] Open WO widget (Task 14)
- [x] Overdue PM widget (Task 14 — uses PmDueCalculator)
- [x] Recently closed WO widget (Task 14)

## Quality

- [x] Workflow tests
- [x] Permission tests
- [x] ERP sync tests
- [x] Validation tests
- [x] Role-scoped Resource tests (Task 14)
- [x] Dashboard tests (Task 14)
- [x] Maintenance history tests (Task 14)
- [ ] UAT issue tracking
