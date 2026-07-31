# Attachment Design

## Scope

Attachments are required for Assets and Parts. The generic attachment model should also support Maintenance Requests and Work Orders.

## Supported Parent Types

Minimum:

- Asset
- Part

Recommended from beginning:

- Asset
- Part
- Maintenance Request
- Work Order

## Examples

Asset attachments:

- User manual
- Maintenance instructions
- Safety instructions
- Calibration certificate
- Warranty document
- Photos

Part attachments:

- Datasheet
- Fitting instructions
- Safety sheet
- Compatibility note
- Usage instructions

Work Order attachments:

- Completion photos
- Repair evidence
- Supporting documents

Maintenance Request attachments:

- Fault photos
- Supporting notes or documents

## Storage

The MVP must use Laravel local storage backed by a persistent Docker volume.
Application containers must not rely on their ephemeral writable layers for
attachment persistence.

MinIO remains an optional future upgrade if attachment volume, backup
separation, or object-storage compatibility requires it. MinIO is not part of
the default MVP deployment.

## Metadata

Each attachment should store:

- Original filename
- Stored filename/path
- MIME type
- File size
- Storage disk
- Parent type
- Parent ID
- Category
- Description
- Uploaded by
- Uploaded at

## Security

- Files should not be publicly accessible by direct path unless explicitly intended.
- Downloads should go through authorized backend routes.
- Users can only access attachments for records they are allowed to view.

## Upload Validation

- Maximum file size: 20 MB per file.
- Allowed document types: PDF, Microsoft Word, and Microsoft Excel.
- Allowed image types: common browser-safe formats such as JPEG, PNG, and WebP.
- Executables, scripts, disk images, and archive formats are rejected.
- Validate file content using server-detected MIME type and extension rules.
- Do not trust the client-provided MIME type or filename extension alone.
- Store the detected MIME type in attachment metadata.

The exact extension/MIME allowlist must be defined centrally in backend
configuration and covered by upload validation tests.

## Deletion

Attachments are soft-deleted for traceability.

- Retain attachment metadata.
- Store `deleted_by_user_id` and `deleted_at`.
- Exclude deleted attachments from normal lists.
- Reject normal download access for deleted attachments.
- Provide no attachment restore UI in MVP.
- Authorization for deletion follows the parent-record policy.

Soft-deleted attachment metadata and physical files are retained indefinitely
for MVP. Do not implement a purge job or configurable retention policy. A
retention policy may be designed in a later phase.
