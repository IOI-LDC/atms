# Codex Backend Prompts

## Prompt: Create Laravel Domain Skeleton

Create the Laravel 13 backend structure for ATMS using PHP 8.4 and PostgreSQL. Use Laravel Sanctum SPA cookie/session authentication for the main web application. Implement models, migrations, policies, form requests, resources, controllers, and action classes for Assets, Parts, Maintenance Requests, Work Orders, PM Rules, Locations, Attachments, ERP Sync Jobs, and Master Data.

Follow these rules:

- Work Orders are created only from approved Maintenance Requests.
- ERP-synced fields are read-only through normal application APIs.
- Attachments must support assets, parts, maintenance requests, and work orders.
- Labor tracking, costing, procurement, full inventory, and ERP write-back are out of scope.

## Prompt: Implement Maintenance Request Approval

Implement the backend workflow where a Maintenance Manager approves a Maintenance Request and the system creates a Work Order.

Requirements:

- Only pending_review requests can be approved.
- Only Maintenance Manager or Administrator can approve.
- Approval should create exactly one Work Order.
- Request status should become converted.
- Do not introduce a separate approved status.
- Work Order should inherit type, asset, title, description, priority from the Maintenance Request.
- Operation should be transactional.
- Add tests.

## Prompt: Implement PM Rule Evaluation

Implement scheduled PM Rule evaluation.

Requirements:

- Evaluate active PM Rules.
- Support date-based rules.
- Support reading-based rules.
- Support date_or_reading rules.
- If due, create a Preventive Maintenance Request with source=system.
- Allow only one active maintenance chain per PM Rule.
- Treat a pending request or an open, in-progress, or completed Work Order as active.
- Create occurrence-level suppression when a preventive request is rejected or cancelled.
- Do not regenerate the same suppressed date/reading occurrence.
- Permit future occurrences only after applicable suppression boundaries are exceeded.
- Record explicit date-triggered and reading-triggered flags.
- Require the suppression boundary for every dimension that triggered, including both when both become due simultaneously.
- Prevent duplicates safely under concurrent evaluation runs.
- Add sync/evaluation logs where useful.
- Add tests.

## Prompt: Implement ERP Asset and Parts Sync

Implement ERP sync job structure for fixed assets and parts.

Requirements:

- Support upsert into local assets and parts tables.
- Use ERP ID/code as identity.
- Store raw ERP payload as JSONB.
- Store sync job summary.
- Store row-level errors.
- No ERP write-back.
- Keep local operational fields untouched.
- Add tests using mocked ERP source data.

## Prompt: Implement Attachment Uploads

Implement generic attachments.

Requirements:

- Parent types: asset, part, maintenance_request, work_order.
- Store file metadata.
- Store files using Laravel Storage.
- Authorize access based on parent record permissions.
- Limit files to 20 MB.
- Allow PDF, common images, Word, and Excel documents.
- Reject executables and archives.
- Detect MIME type server-side and do not trust client-provided MIME metadata.
- Soft-delete attachments with deletion actor/timestamp.
- Hide deleted attachments from normal lists and downloads.
- Do not add a restore UI or endpoint in MVP.
- Provide upload, list, download, and delete endpoints.
- Add tests.

## Prompt: Implement Work Order Completion and Closure

Implement transactional Work Order completion and closure.

Requirements:

- Technician may complete only an eligible Work Order assigned to them.
- Validate and store required completion fields.
- Store completion actor and timestamp.
- Lock Technician execution edits after completion.
- Only Maintenance Manager and Administrator may close a completed Work Order.
- Store closure actor and timestamp.
- Ensure the derived maintenance history reflects the finalized Work Order without copying data into a duplicate history table.
- Update applicable PM baselines.
- Closed Work Orders are permanently immutable.
- Do not add a reopen workflow.
- Add authorization and workflow tests.
