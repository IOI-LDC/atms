# Task 17: Security and Concurrency Verification — Design

**Goal:** Fill genuine test gaps in security and concurrency coverage with focused PHPUnit tests and a smoke script.

**Architecture:** 3 test files covering 11 genuine gaps. Existing coverage (257 tests) is not duplicated. Concurrency tests are labeled as behavioral duplicate-prevention tests (Option B — SQLite-compatible, no `lockForUpdate` dependency).

**Tech Stack:** PHPUnit, Laravel test factories, Sanctum SPA cookie auth, SQLite in-memory.

---

## Security Tests

### `tests/Feature/Security/AuthSecurityTest.php` — 5 tests

1. **Unauthenticated request to protected route returns 401**
   - `GET /api/dashboard` without session → 401

2. **Activation endpoint throttle: 6th rapid attempt returns 429**
   - POST `/auth/activate` 6 times in succession → 429 on 6th

3. **Forgot-password endpoint throttle: 6th rapid attempt returns 429**
   - POST `/auth/forgot-password` 6 times → 429 on 6th

4. **Reset-password endpoint throttle: 6th rapid attempt returns 429**
   - POST `/auth/reset-password` 6 times → 429 on 6th

5. **Deactivated user's authenticated session is rejected**
   - Login as active user → deactivate user → `GET /api/auth/me` → 401
   - Tests Sanctum SPA cookie/session invalidation, not token auth

### `tests/Feature/Security/AttachmentSecurityTest.php` — 5 tests

6. **Path traversal in filename is sanitized/rejected**
   - Upload file named `../../etc/passwd` → filename is sanitized (no `..` in stored_path)

7. **Download returns file stream, not path traversal**
   - Create attachment, GET download endpoint → response is binary stream with `Content-Disposition: attachment`, not a file path

8. **Non-existent attachment returns 404**
   - GET `/api/attachments/999999/download` → 404 (not 500)

9. **Content-Disposition header is set on download**
   - Download a real attachment → response has `Content-Disposition: attachment; filename="..."`

10. **Upload with path traversal in stored_path is not possible**
    - Verify attachment model stores sanitized path (no directory traversal characters in `stored_path` column)

---

## Concurrency Tests

### `tests/Feature/Concurrency/ConcurrencyTest.php` — 1 test

11. **Meter confirmation is idempotent: second confirm succeeds without mutation**
    - Create unconfirmed reading → confirm → confirm again
    - Second confirm returns 200 (success)
    - `confirmed_at` and `confirmed_by_user_id` remain unchanged after second call
    - No 409 expected — idempotent, not exclusive

---

## Smoke Script

### `scripts/security-smoke.sh`

```sh
#!/usr/bin/env sh
set -eu
echo "Running security and concurrency tests..."
docker compose run --rm api php artisan test tests/Feature/Security tests/Feature/Concurrency
echo "Done."
```

---

## What Is NOT Tested Here

Already covered by existing tests — no duplication:

- Login rate limiting (`AuthTest::test_login_rate_limited`)
- Inactive account denial (`AuthTest::test_inactive_user_cannot_authenticate`)
- Session invalidation on deactivation (`UserManagementTest::test_deactivation_invalidates_sessions`)
- Cross-role field restrictions (6 ReadModel test files)
- Closed/cancelled immutability (`WorkOrderLifecycleTest`)
- Audit log mutation blocked (`AuditLogTest`)
- Secret redaction (`AuditLogTest`, `HealthEndpointTest`)
- Exactly-one WO conversion (`MaintenanceRequestWorkflowTest`)
- PM deactivation active-chain check (`PmWorkflowTest`)
- MIME mismatch rejection (`AttachmentWorkflowTest::test_upload_detects_mime_type_server_side`)
- Soft-deleted attachment download 404 (`AttachmentWorkflowTest::test_download_soft_deleted_attachment_returns_404`)

---

## Key Decisions

- **Option B for concurrency**: SQLite doesn't support `lockForUpdate`. Tests verify behavioral duplicate-prevention (application-level constraints), not row-level locking. Labeled as behavioral, not true concurrency.
- **Sanctum SPA cookie auth**: Deactivation test uses `actingAs()` to simulate cookie session, not API tokens.
- **Compose service is `api`**: Not `app`. Verified in `compose.yaml`.
- **No idempotency tests for WO transitions**: Double-close/cancel/complete coverage deferred unless gaps found during implementation.
