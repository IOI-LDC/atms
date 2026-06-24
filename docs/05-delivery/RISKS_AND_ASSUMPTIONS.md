# Risks and Assumptions

## Assumptions

- ERP connection is **Microsoft Entra ID OAuth2** (client credentials grant)
  against **Dynamics 365 Business Central OData V4** API. Token auth confirmed
  and working. Fixed assets endpoint confirmed (`fixedAssestAPI`). Parts
  endpoint will follow the identical auth + URL pattern once the API page name
  is provided by the ERP team.
- ERP parts sync is **read-only** in MVP. Parts consumption write-back (SM → ERP
  when stock is issued at Goods Receipt) is under discussion with LDC — see
  [`sm/01-product/LDC_MEETING_PARTS_WRITEBACK.md`](../sm/01-product/LDC_MEETING_PARTS_WRITEBACK.md).
- ERP integration is **parts only**. Assets are managed fully within ATMS; no
  ERP asset sync.
- The system will be deployed for a single client environment.
- Native mobile app is not part of MVP.
- Financial tracking and labor tracking are excluded.
- Parts inventory and stock movement are owned by the SM (Store Management)
  subsystem. ATMS reads parts from SM for Work Order part-request forms only.
- Usage readings are manually entered unless a simple import source is provided.
- Asset physical location and location history are owned by the AM (Asset
  Movement) subsystem.

## Risks

### Parts API Page Name Delay

Risk: Parts sync cannot proceed until the ERP team provides the custom BC API
page name for parts/items.

Mitigation: All other ERP integration components are built and tested —
`ErpSource` contract, `LdcErpHttpSource` adapter, token exchange, OData response
parsing, and sync job structure. Once the page name is received, only the
endpoint URL and field mapping need to be added. See
[`TDL.md`](./TDL.md) for live tracking.

### ERP Field Ownership Conflict

Risk: ERP sync could overwrite fields that ATMS manages locally (e.g. `name`,
`is_active`, `category`), undoing operational updates made by ATMS users.

Mitigation: Sync job writes to ERP-owned columns only (`erp_part_id`,
`erp_part_code`, `erp_status`, `erp_raw_data`, `erp_last_synced_at`). ATMS
local fields (`name`, `description`, `is_active`, `unit_of_measure`, `category`)
are never touched by sync. Documented in [`ERP_SYNC.md`](../03-backend/ERP_SYNC.md).

### Poor ERP Data Quality

Risk: Parts data may contain duplicates, missing identifiers, or inconsistent
naming.

Mitigation: Store raw payloads, sync errors, and allow operational fields to
exist locally. The sync job validates each record and logs row-level errors.

### PM Trigger Accuracy

Risk: Preventive maintenance depends on accurate usage readings.

Mitigation: Make readings visible, traceable, and easy to update. Add validation
for decreasing readings.

### Scope Creep

Risk: Client may ask for procurement, inventory, labor cost, logistics, or
mobile app during delivery.

Mitigation: Keep in-scope and out-of-scope documents visible and require change
control for added modules. Three-subsystem architecture (ATMS / SM / AM) scopes
each domain explicitly.

### Attachment Storage Growth

Risk: Attachments may grow over time.

Mitigation: Start with reasonable upload limits and consider MinIO if volume
becomes significant.

### Backup or Restore Failure

Risk: Database or attachment backups may be missing, incomplete, or unusable
when recovery is required.

Mitigation: Run nightly PostgreSQL and attachment-volume backups, retain seven
daily and four weekly copies, store backups separately from active volumes, and
periodically verify documented restore procedures.
