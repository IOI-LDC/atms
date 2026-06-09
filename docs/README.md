# ATMS Documentation Pack

**Project:** Asset Maintenance Tracking System (ATMS)  
**Purpose:** A simplified operational asset maintenance application integrated with ERP fixed assets and parts reference data.

This folder contains the working documentation set for product discovery, design, backend implementation, frontend implementation, delivery planning, and Codex-assisted development.

## Locked Product Direction

ATMS is an operational maintenance system. It is not an ERP, financial asset register, procurement system, warehouse system, logistics system, or document management platform.

The core workflow is:

**Maintenance Request → Maintenance Manager Approval → Work Order → Closure → Asset Maintenance History**

Maintenance Requests can be generated in two ways:

1. **Preventive Maintenance (PM):** generated automatically by the system based on PM rules such as date, operating hours, kilometers, or other usage readings.
2. **Corrective Maintenance (CM):** created manually by a user when an asset is faulty, damaged, underperforming, or requires repair.

ERP remains the source of truth for fixed assets and parts. ATMS keeps a local operational copy where needed for maintenance, history, usage, attachments, location tracking, and workflow integrity.

## Folder Structure

- `01-product/` — PRD, scope, out-of-scope, workflows, roles, client-facing notes.
- `02-design/` — navigation, screen inventory, UX principles, frontend screen behaviour.
- `03-backend/` — backend architecture, schema, API plan, ERP sync, RBAC, jobs, attachments.
- `04-frontend/` — frontend architecture, routes, state, components, UI conventions.
- `05-delivery/` — implementation plan, milestones, task delivery list, risks.
- `06-prompts/` — Codex prompts and implementation instructions.
- `07-meetings/` — client questions, meeting notes templates, discovery checklist.

## Locked Technology Stack

- **Frontend:** Vue 3 + TypeScript + Tailwind
- **Backend:** Laravel 13 API backend
- **Runtime:** PHP 8.4
- **Database:** PostgreSQL
- **Deployment:** One Docker Compose service model for local OrbStack development and VPS production, with environment-specific overrides
- **Background Jobs:** Laravel Queues using the PostgreSQL database driver for MVP
- **Scheduled Jobs:** Laravel Scheduler
- **ERP Sync:** Scheduled Laravel jobs into local operational tables through an ERP adapter
- **Mock ERP:** Separate lightweight container, enabled through an explicit Docker Compose profile and available only on the internal Docker network
- **Attachments:** Laravel local storage on a persistent Docker volume
- **Auth/RBAC:** Laravel Sanctum SPA cookie/session authentication with role-based permissions
- **Production Account Email:** Microsoft Power Automate for activation and password-reset delivery
- **Company Portal:** SharePoint contains a normal link to the separately hosted ATMS web application; ATMS is not embedded in or deployed to SharePoint

Redis and MinIO are optional future upgrades and are not part of the default MVP deployment.

All timestamps are stored in UTC. The initial company display timezone is
`Africa/Tripoli`.

## Frontend Design Authority

Use `02-design/UI_DESIGN_SYSTEM.md` for visual and interaction standards.
Product behavior, workflows, roles, and permissions remain authoritative over
visual references and component examples.
