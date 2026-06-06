# Database Schema Draft

This is a working draft. Final names and fields should be adjusted during implementation.

Asset maintenance history is a derived read model. Do not add a duplicate
`maintenance_histories` table; assemble it from Maintenance Requests, Work
Orders, Work Order parts, meter readings, location histories, and attachments.

## employees

Local reference copy of employees imported from the client's SharePoint List.
Importing an employee does not create an ATMS user account.

Key fields:

- id
- sharepoint_item_id
- emp_id
- name
- email
- department nullable
- job_title nullable
- source_is_active
- source_updated_at nullable
- source_raw_data JSONB
- last_synced_at
- created_at
- updated_at

The exact SharePoint field names, authentication method, and mapping must be
confirmed when client API access is available.

## users

Stores application users.

Key fields:

- id
- emp_id
- name
- email
- password
- employee_id nullable
- role_id
- is_active
- activated_at nullable
- activation_sent_at nullable
- created_at
- updated_at

Users are never physically deleted through the application. Administrators set
`is_active` to false to deactivate access. Historical foreign-key and audit
references remain intact.

An Administrator creates an ATMS user only by selecting an imported employee
and assigning one fixed role. Employee import alone never grants access.
Activation and password-reset tokens are one-time, stored hashed, expire after a
configured period, and become invalid after use. Administrators never set,
view, email, or log plaintext passwords.

Activation links expire after 24 hours. Password-reset links expire after 60
minutes.

`employees.emp_id` is the unique company employee identifier imported from
SharePoint. Provisioning copies it to `users.emp_id` and links
`users.employee_id` to the source employee record. Both user identity fields are
unique and immutable after provisioning.

## roles

Stores the six seeded, immutable system roles. Administrators can assign roles
to users but cannot create, rename, or delete roles.

Required roles:

- Administrator
- Maintenance Manager
- Technician
- Logistics
- Requester
- Viewer

Each user has exactly one `role_id`. Separate permission and role-permission
tables are not required for MVP; authorization is implemented through Laravel
policies using the fixed role definitions. A `user_roles` many-to-many table
must not be introduced for MVP.

## assets

Local operational copy of ERP fixed assets.

Key fields:

- id
- erp_asset_id
- erp_asset_code
- name
- description
- category
- serial_number
- model
- manufacturer
- current_location_id
- operational_status
- erp_status
- erp_raw_data JSONB
- erp_last_synced_at
- is_active
- created_at
- updated_at

## parts

Local reference copy of ERP parts.

Key fields:

- id
- erp_part_id
- erp_part_code
- name
- description
- unit_of_measure
- category
- erp_status
- erp_raw_data JSONB
- erp_last_synced_at
- is_active
- created_at
- updated_at

## locations

Physical locations used in dropdowns and location history.

Key fields:

- id
- parent_id
- name
- type
- code
- description
- is_active
- created_at
- updated_at

## asset_location_histories

Tracks physical location changes.

Key fields:

- id
- asset_id
- from_location_id
- to_location_id
- effective_at
- reason
- notes
- changed_by_user_id
- created_at

## usage_reading_types

Configurable reading types.

Examples:

- Operating Hours
- Kilometers
- Cycles
- Fuel Hours

Key fields:

- id
- name
- unit
- is_active

## asset_meter_readings

Stores readings for assets.

Key fields:

- id
- asset_id
- usage_reading_type_id
- reading_value
- reading_at
- source
- entered_by_user_id
- maintenance_request_id nullable
- confirmed_by_user_id nullable
- confirmed_at nullable
- notes
- created_at

No reading status column is required. A reading is confirmed when both
`confirmed_by_user_id` and `confirmed_at` are present. Only confirmed readings
may update current meter values or be used by PM rule evaluation.

Confirmed readings are append-only and must not be lower than the latest
confirmed reading for the same asset and reading type. No decreasing-reading
override is included in MVP.

## pm_rules

Preventive maintenance rules.

Key fields:

- id
- asset_id
- title
- description
- trigger_type
- usage_reading_type_id nullable
- interval_value nullable
- interval_days nullable
- last_completed_at nullable
- last_completed_reading_value nullable
- next_due_at nullable
- next_due_reading_value nullable
- is_active
- created_by_user_id
- created_at
- updated_at

PM Rules are never physically deleted through the application. Deletion means
setting `is_active` to false. Inactive rules remain available for historical
references and may be reactivated by an authorized user.

Deactivation must be blocked while the PM Rule has an active maintenance chain:
a `pending_review` Maintenance Request or a converted Work Order in `open`,
`in_progress`, or `completed`. Historical request, Work Order, and suppression
references remain linked after deactivation.

Trigger examples:

- date
- reading
- date_or_reading

PM evaluation must enforce one active maintenance chain per PM Rule. Historical
requests and Work Orders remain linked to the rule, but a new preventive request
must not be created while the rule has a `pending_review` request or a converted
Work Order in `open`, `in_progress`, or `completed`.

This rule must be enforced transactionally and safely under concurrent scheduler
or manual evaluation runs. The exact PostgreSQL locking or constraint strategy
will be finalized in implementation design.

## pm_occurrence_suppressions

Prevents a rejected or cancelled preventive request occurrence from being
regenerated repeatedly.

Key fields:

- id
- pm_rule_id
- maintenance_request_id
- asset_id
- trigger_type
- triggered_by_date
- triggered_by_reading
- trigger_date nullable
- trigger_reading_value nullable
- trigger_reading_type_id nullable
- decision_type: rejected/cancelled
- reason
- suppressed_until_date nullable
- suppressed_until_reading nullable
- decided_by_user_id
- decided_at
- created_at

The suppression records the PM occurrence as it existed when the preventive
request was generated and decided. The scheduler must compare due occurrences
against suppression records before generating a new request.

Suppression validation is based on the dimensions that generated the occurrence:

- If `triggered_by_date` is true, `trigger_date` and `suppressed_until_date` are required.
- If `triggered_by_reading` is true, `trigger_reading_value`, `trigger_reading_type_id`, and `suppressed_until_reading` are required.
- For a `date_or_reading` rule where both dimensions become due in the same evaluation, both booleans are true and both suppression boundaries are required.
- At least one trigger-dimension boolean must be true.

## maintenance_requests

Approval-stage maintenance object.

Key fields:

- id
- request_no
- type: preventive/corrective
- source: system/user
- asset_id
- pm_rule_id nullable
- status: pending_review/rejected/converted/cancelled
- priority
- title
- description
- fault_type_id nullable
- requested_by_user_id nullable
- reviewed_by_user_id nullable
- reviewed_at nullable
- rejection_reason nullable
- cancelled_by_user_id nullable
- cancelled_at nullable
- cancellation_reason nullable
- triggered_by_date
- triggered_by_reading
- trigger_reading_value nullable
- trigger_reading_type_id nullable
- trigger_date nullable
- created_at
- updated_at

`request_no` uses the human-readable format `MR-######`. It is generated from a
PostgreSQL sequence or equivalent database-atomic mechanism, is unique, and is
separate from the internal primary key.

## work_orders

Execution-stage maintenance object.

Key fields:

- id
- work_order_no
- maintenance_request_id
- asset_id
- type: preventive/corrective
- priority
- status: open/in_progress/completed/closed/cancelled
- completed_by_user_id nullable
- completed_at nullable
- assigned_to_user_id nullable
- title
- description
- work_notes
- final_asset_status
- closed_by_user_id nullable
- closed_at nullable
- cancelled_by_user_id nullable
- cancelled_at nullable
- cancellation_reason nullable
- created_at
- updated_at

`work_order_no` uses the human-readable format `WO-######`. It is generated from
a PostgreSQL sequence or equivalent database-atomic mechanism, is unique, and
is separate from the internal primary key.

`priority` is copied from the Maintenance Request when approval atomically
creates the Work Order. It is stored as a Work Order snapshot and is not a
dynamic reference to the request or master-data label.

## work_order_parts

Tracks parts used on work orders.

Key fields:

- id
- work_order_id
- part_id
- quantity_used
- unit_of_measure
- notes
- recorded_by_user_id
- created_at

No financial cost fields are included in MVP.

## attachments

Generic attachment model.

Key fields:

- id
- parent_type
- parent_id
- file_name
- original_file_name
- mime_type
- file_size
- storage_disk
- storage_path
- category
- description
- uploaded_by_user_id
- deleted_by_user_id nullable
- deleted_at nullable
- created_at

Attachments use application-level soft deletion. Deleted attachments are hidden
from normal queries and downloads while metadata remains available for
traceability. No restore UI is included in MVP.

Minimum required parent types:

- asset
- part
- maintenance_request
- work_order

## erp_sync_jobs

Records sync runs.

Key fields:

- id
- sync_type: assets/parts
- status: running/success/failed/partial
- started_at
- completed_at
- total_records
- created_count
- updated_count
- skipped_count
- failed_count
- error_message
- triggered_by_user_id nullable
- created_at

## erp_sync_errors

Records row-level sync errors.

Key fields:

- id
- erp_sync_job_id
- external_id
- error_type
- error_message
- payload JSONB
- created_at

## master_data_items

Configurable dropdown values.

Key fields:

- id
- group_key
- value
- label
- sort_order
- is_active
- created_at
- updated_at

Example group keys:

- asset_status
- maintenance_priority
- fault_type
- location_type
- work_order_status
- maintenance_category

## audit_logs

Append-only technical audit records.

Key fields:

- id
- event
- actor_user_id nullable
- subject_type nullable
- subject_id nullable
- description nullable
- before_data JSONB nullable
- after_data JSONB nullable
- ip_address nullable
- user_agent nullable
- request_id nullable
- created_at

Audit context must be allowlisted or redacted. Never store passwords, session
cookies, service API keys, attachment contents, or unredacted secrets.

Audit logs cannot be updated or deleted through application APIs.
Audit logs are retained indefinitely in MVP.
