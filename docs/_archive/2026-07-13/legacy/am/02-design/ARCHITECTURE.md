# Asset Movement (AM) — Architecture

> **Status:** Placeholder. AM has no code yet.

AM shares the **single Laravel backend and PostgreSQL database** with ATMS and SM (see `docs/03-backend/ARCHITECTURE.md`). There is one backend service, one queue, one scheduler, and one database for all three subsystems.

## Source of truth

- **Asset current location** and **location history** live in AM tables — the authoritative source for where an asset physically is. ATMS reads current location from AM tables for display only.
