# Implementation Plan

## Phase 0 — Project Setup

- Repository setup
- Shared Docker Compose service model for OrbStack and VPS
- Environment-specific Docker Compose overrides/configuration
- Laravel backend skeleton
- Vue frontend skeleton
- PostgreSQL setup
- PostgreSQL-backed Laravel queue setup
- Persistent attachment storage volume
- Auth baseline
- Environment configuration

## Phase 1 — Core Master Data and ERP Sync Foundation

- SharePoint employee adapter and manual import
- Local employee directory
- Administrator employee-to-user provisioning
- One-time activation and password-reset flow
- Users and roles
- Locations
- Master data/dropdowns
- Mock ERP container and Docker Compose profile
- Mock ERP read-only asset and part API
- Deterministic mock ERP seed data
- ERP adapter contract
- ERP sync job structure
- Asset sync
- Parts sync
- Sync history and errors

## Phase 2 — Asset Registry

- Asset list
- Asset detail
- Asset usage readings
- Asset location update
- Asset location history
- Asset attachments
- Asset maintenance history read-model foundation

## Phase 3 — Maintenance Requests

- Corrective MR creation
- PM-generated MR structure
- MR list and filters
- MR review screen
- Approval and rejection workflow
- Approved MR creates WO

## Phase 4 — Work Orders

- Active WO list
- WO detail
- Parts used on WO
- WO attachments
- WO closure
- Reflect closed Work Orders in the derived maintenance history
- Closed WO list

## Phase 5 — PM Rules and Scheduler

- PM rule management
- Usage/date-based trigger logic
- Scheduled PM evaluation
- Automatic preventive MR generation
- PM baseline update on closure

## Phase 6 — Dashboard and Reports

- Dashboard summary cards
- Pending MRs
- Open WOs
- Overdue PMs
- Recently closed WOs
- Asset maintenance history views

## Phase 7 — Hardening and UAT

- Permissions review
- Validation review
- Error handling
- Test coverage
- Client UAT fixes
- Deployment preparation

## Phase 8 — Deployment

- VPS setup
- Docker deployment
- Enable mock ERP profile only when required for a demo
- Backup plan
- Nightly PostgreSQL and attachment-volume backups
- Seven daily and four weekly retained copies
- Restore procedure and verification
- SSL/domain setup
- Initial ERP sync
- User setup
- Go-live support
