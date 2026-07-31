# Asset Movement (AM) — Product Requirements

> **Status:** Placeholder. AM is not yet built. This document captures agreed scope so the docs tree is not broken.

## Purpose

Asset Movement (AM) is the subsystem that owns **physical asset location tracking and movement requests**. It is a peer subsystem to ATMS and SM, sharing a single Laravel backend and PostgreSQL database.

## Scope

AM is responsible for:

- **Asset location** — the canonical record of an asset's current location and full location history.
- **Movement request workflow:** Requester submits a movement request → Logistics approves → Logistics confirms arrival at the destination → the asset's current location is updated in AM tables.
- **Location history** — append-only history of every location an asset has occupied.

## Relationship to ATMS

ATMS does not own asset location. ATMS reads the asset's current location from AM tables for display purposes only. ATMS never writes location data directly.

## Workflow summary

1. **Requester** submits an asset movement request (asset + destination).
2. **Logistics** approves the request.
3. After physical movement, **Logistics** confirms arrival.
4. AM updates the asset's current location and appends a location-history record.

## Out of scope for AM (MVP)

- Custody/chain-of-custody handovers, multi-leg transit tracking (future phases).
