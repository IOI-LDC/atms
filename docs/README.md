# ATMS / Product Family Documentation Pack

**Project:** Asset Maintenance Tracking System (ATMS) and its peer subsystems
**Purpose:** Operational asset maintenance, store management, and asset movement applications sharing one backend.

This folder contains the working documentation set for product discovery, design, backend implementation, frontend implementation, delivery planning, and operations.

## Locked Product Direction

The product family is **one backend, one database, three subsystems**:

| Subsystem | Owns | Docs folder |
|---|---|---|
| **ATMS** (Asset Maintenance Tracking) | Assets, Maintenance Requests, Work Orders, PM rules, dashboard, RBAC | `atms/` |
| **SM** (Store Management) | Parts catalogue, inventory, stock movement, ERP parts sync, Order → Approval → Dispatch → GR | `sm/` |
| **AM** (Asset Movement) | Asset movement form, location history, movement workflow | `am/` |

All three subsystems are operational systems. They are not an ERP, financial asset register, procurement system, warehouse system, logistics system, or document management platform.

### ATMS core workflow

**Maintenance Request → Maintenance Manager Approval → Work Order → Closure → Asset Maintenance History**

Maintenance Requests can be generated in two ways:

1. **Preventive Maintenance (PM):** generated automatically by the system based on PM rules such as date, operating hours, kilometers, or other usage readings.
2. **Corrective Maintenance (CM):** created manually by a user when an asset is faulty, damaged, underperforming, or requires repair.

### Source-of-truth boundaries

- **Assets** are managed fully within ATMS. There is no ERP asset sync.
- **Parts** are owned by SM — ERP syncs parts into SM tables; ATMS reads parts only to populate a Work Order part-request form, and that form submits into SM's workflow.
- **Asset location** is owned by AM — ATMS reads current location from AM tables for display only.
- **ERP** remains the source of truth for parts reference data (synced into SM). It is no longer the source of truth for fixed assets.

## Folder Structure

```
docs/
├── README.md                  ← this file
├── 00-project-rules/          ← authoritative-sources, project-wide rules
├── 03-backend/                ← shared backend architecture, RBAC, status model, jobs, ERP sync, attachments, notifications, secure remote API access
├── 05-delivery/               ← implementation plan, milestones, risks, TDL
├── operations/                ← deployment, backup & restore
├── atms/                      ← ATMS subsystem
│   ├── 01-product/            ← PRD, scope, workflows, roles, asset status
│   ├── 02-design/             ← navigation, screens, UX, design system
│   ├── 04-frontend/           ← frontend architecture, routes, components, VPS issue tracker
│   └── 04-technical/          ← backend API reference & handoff for ATMS
├── sm/                        ← Store Management (placeholder — not built yet)
│   ├── 01-product/
│   ├── 02-design/
│   └── 04-frontend/
└── am/                        ← Asset Movement (placeholder — not built yet)
    ├── 01-product/
    ├── 02-design/
    └── 04-frontend/
```

The `03-backend/`, `00-project-rules/`, `05-delivery/`, and `operations/` folders are shared across all three subsystems and live at the root of `docs/`.

## Locked Technology Stack

- **Frontend:** Vue 3 + TypeScript + Tailwind + shadcn-vue (one app per subsystem)
- **Backend:** Laravel 13 API backend (shared by ATMS, SM, AM)
- **Runtime:** PHP 8.4
- **Database:** PostgreSQL (shared)
- **Deployment:** One Docker Compose service model for local OrbStack development and VPS production, with environment-specific overrides
- **Background Jobs:** Laravel Queues using the PostgreSQL database driver for MVP
- **Scheduled Jobs:** Laravel Scheduler
- **ERP Sync:** Scheduled Laravel jobs into SM parts tables through an ERP adapter (parts only; no asset sync)
- **ERP Sync:** Parts master data synced from LDC ERP into SM tables using client-credentials token auth. No asset sync.
- **Attachments:** Laravel local storage on a persistent Docker volume
- **Auth/RBAC:** Laravel Sanctum SPA cookie/session authentication with role-based permissions
- **Notifications / Email Delivery:** Transactional emails (MR created, WO assigned, WO completed, account activation, password reset) are delivered via **Microsoft Graph `sendMail`** (OAuth2 client-credentials) from the corporate mailbox `notification@ldc.com.ly`. SMTP AUTH is ruled out (LDC M365 tenant disables it); Power Automate is a viable alternative but not chosen. Templates are rendered Laravel-side (Mailable + Blade) and sent via a queued, throttle-aware transport. See `03-backend/NOTIFICATIONS.md`.
- **Company Portal:** SharePoint contains a normal link to the separately hosted product web applications; they are not embedded in or deployed to SharePoint

Redis and MinIO are optional future upgrades and are not part of the default MVP deployment.

All timestamps are stored in UTC. The initial company display timezone is
`Africa/Tripoli`.

## RBAC

Five roles: **Administrator, Maintenance Manager, Technician, Logistics, Requester**.
All users are Requesters at minimum; the legacy Viewer role has been merged into Requester. Logistics owns the AM movement-approval workflow. See `atms/01-product/ROLES_AND_PERMISSIONS.md` and `03-backend/RBAC.md` for the permission matrix.

## Frontend Design Authority

Use `atms/02-design/UI_DESIGN_SYSTEM.md` for visual and interaction standards.
Product behavior, workflows, roles, and permissions remain authoritative over
visual references and component examples.

## Key Documents

| Document | Purpose |
|----------|---------|
| `atms/04-frontend/VPS_FRONTEND_ISSUES.md` | Live issue tracker for frontend bugs found during VPS deployment testing |
| `03-backend/NOTIFICATIONS.md` | Notification/email transport (Microsoft Graph sendMail), triggers, Azure provisioning, secret/cert expiry, pre-release checklist |
| `05-delivery/TDL.md` | Task Delivery List — items blocked on external dependencies or pending decisions |
| `PHASE_1_GAP_ANALYSIS.md` | Phase 1 code gaps discovered during audit |
| `atms/04-technical/BACKEND_API_REFERENCE.md` | Backend API reference for ATMS |
| `atms/01-product/WO_FORMS.md` | Work Order Execution Forms — configurable pre/post-maintenance forms mapped by FA subclass |
| `atms/04-technical/BACKEND_API_HANDOFF.md` | Backend-to-frontend API handoff document |
