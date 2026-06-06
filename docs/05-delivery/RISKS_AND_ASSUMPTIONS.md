# Risks and Assumptions

## Assumptions

- The client ERP connection method is not yet known; an HTTP API is currently the most likely method.
- ERP integration is read-only in MVP.
- The system will be deployed for a single client environment.
- Native mobile app is not part of MVP.
- Financial tracking and labor tracking are excluded.
- Parts are reference data from ERP, not fully managed inventory.
- Usage readings are manually entered unless a simple import source is provided.

## Risks

### ERP Access Complexity

Risk: ERP data access may be delayed, undocumented, or inconsistent.

Mitigation: Build an ERP adapter boundary and ship a separate profile-based mock
ERP HTTP service for development and demos. Implement the real adapter only
after the client transport, authentication, and field mapping are confirmed.

### Poor ERP Data Quality

Risk: Asset and parts data may contain duplicates, missing identifiers, or inconsistent naming.

Mitigation: Store raw payloads, sync errors, and allow operational fields to exist locally.

### PM Trigger Accuracy

Risk: Preventive maintenance depends on accurate usage readings.

Mitigation: Make readings visible, traceable, and easy to update. Add validation for decreasing readings.

### Scope Creep

Risk: Client may ask for procurement, inventory, labor cost, logistics, or mobile app during delivery.

Mitigation: Keep in-scope and out-of-scope documents visible and require change control for added modules.

### Attachment Storage Growth

Risk: Attachments may grow over time.

Mitigation: Start with reasonable upload limits and consider MinIO if volume becomes significant.

### Backup or Restore Failure

Risk: Database or attachment backups may be missing, incomplete, or unusable
when recovery is required.

Mitigation: Run nightly PostgreSQL and attachment-volume backups, retain seven
daily and four weekly copies, store backups separately from active volumes, and
periodically verify documented restore procedures.
