# RQ3 — Parts CSV download + quantity upload (mini-spec)

**Status: PROPOSED 2026-08-17 — discharges the Phase 7 design gate** once the
§5 decisions are confirmed. Nothing here is built yet.

Pins what the gate demanded: export route/controller + exact CSV columns +
error contract; upload route/request/action + UI + tests. Q6 and Q8 semantics
are already settled (2026-08-16) and are restated here only as constraints.

---

## 1. The workflow this serves (LDC, 2026-08-17)

The team reconciles ATMS stock against the ERP in Excel:

1. **Download the parts list from ATMS** (CSV).
2. **Download the parts list from the ERP** (their existing export).
3. **VLOOKUP in Excel** — match the ERP quantities onto the ATMS rows.
4. **Produce a CSV and upload it to ATMS**, which applies the corrected
   quantities.

Design consequence: the ATMS download must carry the columns the ERP export
can be VLOOKUPed against — **both `erp_part_id` (the ERP system ID, the key
the ERP export uses) and `erp_part_code` (the business code the team reads)**
— plus the ATMS key the upload round-trips on.

**Constraints inherited from Q6/Q8 (not re-litigable here):**

- **ERP remains the quantity authority.** This upload is an *interim*
  correction path; `SyncParts` keeps overwriting `available_quantity`
  wholesale once the live ERP feed (`LDC_ERP_PARTS_API`, TLD 🟠) lands. The
  UI must say so.
- **The round-trip keys on `parts.id`** (table PK). `erp_part_code` is
  display/search only — not guaranteed unique, LDC edits it — and must never
  be a matching key. `erp_part_id` is carried for ERP correlation only.

---

## 2. What is already built (reuse, do not rebuild)

- **`CsvReportStreamer`** (`backend/app/Support/Reports/CsvReportStreamer.php`)
  — streaming CSV responses, dated filename `{slug}-{YYYY-MM-DD}.csv`. The
  export uses this; no new CSV machinery.
- **`ImportPartsCommand`** — the validation canon this phase copies, not
  generalises: all rows validated **before** the transaction begins;
  all-or-nothing; line-numbered errors; never inserts, never deletes;
  cross-checks a human-readable identifier against the immutable key to catch
  shifted VLOOKUPs.
- **`RecordWorkOrderPart` / `DeleteWorkOrderPart`** — both lock the part row
  (`lockForUpdate`) before touching `available_quantity`. The upload must do
  the same, per row, inside its transaction, so a stock correction and a WO
  consumption serialise instead of clobbering each other.
- **`PartPolicy::manage`** — Admin + Maintenance Manager gate for part
  mutations. (Scope decision D4 below: the manual currently promises
  Administrator-only.)

---

## 3. The download

**`GET /parts/export-csv`** — Admin-only (per D4), streamed via
`CsvReportStreamer`, slug `parts`.

**Route ordering gotcha:** must be registered *above* `GET /parts/{part}` in
`routes/api.php`, or `export-csv` binds as `{part}` and 404s.

| Column | Source | Why |
|---|---|---|
| `part_id` | `parts.id` | The upload's matching key (Q8). First column so Excel users see it. |
| `erp_part_id` | `parts.erp_part_id` | VLOOKUP key against the ERP export. |
| `erp_part_code` | `parts.erp_part_code` | The Part No. the team reads; upload cross-check. |
| `name` | `parts.name` | Human verification. |
| `unit_of_measure` | `parts.unit_of_measure` | Human verification. |
| `erp_status` | `parts.erp_status` | Context (Active/Obsolete). |
| `is_active` | `parts.is_active` | Context (`true`/`false`). |
| `available_quantity` | `parts.available_quantity` | The value being reconciled. Plain decimal (`12`, `12.5`), never locale-formatted. |

Includes **all** parts (active and inactive) — a physical stock count does not
stop at the catalogue edge, and inactive rows can be excluded in Excel.

Read-only columns are informational only: the upload **ignores** everything
except the three columns in §4. Re-uploading an unedited download is a no-op,
by design.

---

## 4. The upload

**`POST /parts/import-quantities`** — Admin-only (per D4), multipart `file`.

### 4.1 File contract

Required headers, exactly: **`part_id`, `erp_part_code`, `available_quantity`.**
Extra columns are ignored (so the team can upload the download file — or their
VLOOKUP worksheet with helper columns — untouched). Blank rows skipped.
File limit: 5 MB / 25,000 data rows (D6) — well above the catalogue, cheap to
enforce, keeps one bad file from being an availability problem.

### 4.2 Validation — all-or-nothing, before any write

Copied from `ImportPartsCommand`'s canon. Every row must pass before the
transaction opens; one bad row rejects the whole file:

| Check | Error (line-numbered) |
|---|---|
| `part_id` blank or non-integer | `line N: part_id is blank / invalid.` |
| `part_id` not in `parts` | `line N: part_id NNN does not match any existing part.` — **never insert.** |
| Duplicate `part_id` in file | `line N: duplicate part_id NNN (also line M).` |
| `erp_part_code` mismatch with DB | `line N: part_id NNN does not match database erp_part_code [X]; file has [Y].` — the shifted-VLOOKUP catch. |
| Quantity fails `/^\d{1,11}(\.\d{1,3})?$/` | `line N: PART-CODE invalid available_quantity [raw].` — non-negative, max three decimals to match `numeric(14,3)` (D5). |

Error response: **422** with `{ errors: [ "line 12: …", … ], total_rows, valid_rows }`
— first 40 errors plus a "… N more" count, mirroring the CLI's display rule.
Nothing is written.

### 4.3 Apply

One transaction. Per row: `Part::where('id', …)->lockForUpdate()`, set
`available_quantity` **wholesale** (overwrite, not delta — ERP-authority
semantics), count updated vs unchanged (`isDirty`). Parts absent from the file
are untouched — the file is a partial correction, not a census.

### 4.4 Audit

One summary event per upload — not per row; a catalogue-wide correction is
2,000+ rows and per-row events would drown the log:

| Event | Metadata |
|---|---|
| `parts.quantity_upload.completed` | filename, rows, updated, unchanged, sha256 of the uploaded file |

(Per-part history stays readable via the existing audit trail on the part's
own updates; the upload's provenance is the file hash.)

### 4.5 Success response

`{ rows, updated, unchanged }` — the UI shows exactly this.

---

## 5. Decisions — NEED CONFIRMATION

1. **D1 — Upload scope is quantity-only.** No name/status/category/size edits
   through this path (the CLI command already did that migration once). The
   interim purpose is stock correction; everything else stays ERP-owned.
   *(Recommended: yes.)*
2. **D2 — All-or-nothing validation**, matching `ImportPartsCommand`.
   Alternative is row-by-row apply-with-errors, but a half-applied stock
   correction is worse than a rejected one — the team fixes the file and
   retries, and the file is their source of truth either way.
   *(Recommended: all-or-nothing.)*
3. **D3 — Key on `part_id`, cross-check `erp_part_code`.** Already pinned by
   Q8; recorded so nobody "simplifies" this to match on the part code.
   *(Pinned — not open.)*
4. **D4 — Authorization: Administrator only**, matching the manual ("an
   interim, Administrator-only process"). `PartPolicy::manage` would also
   admit Maintenance Manager. If Manager should be admitted, §10.4a of the
   manual changes instead. *(Recommended: Admin-only, keep the manual as
   written.)*
5. **D5 — Reject negative quantities.** A physical count cannot be negative;
   a negative here is a sign error in the worksheet, and WO consumption
   already refuses to drive stock below zero. (`ImportPartsCommand` tolerated
   negatives because it was a one-off migration of real ERP data; this is a
   correction surface.) *(Recommended: reject.)*
6. **D6 — 5 MB / 25,000-row cap.** *(Recommended: as stated.)*
7. **D7 — One summary audit event per upload**, not per row.
   *(Recommended: as stated.)*

---

## 6. Frontend

Parts section toolbar (Admin-only controls):

- **"Download CSV"** button → `GET /parts/export-csv` (plain link/download).
- **"Upload quantities"** → existing `FileInput` primitive → **confirm dialog
  before submit** stating the overwrite semantics: *"This replaces the
  available quantity for every part listed in the file. The next ERP sync
  will overwrite these quantities again."* Disable submit while pending.
- **Result states**: success toast with `updated`/`unchanged` counts; 422 →
  inline error list (line-numbered, scrollable, first 40 + count); file too
  large → its own message. Loading / empty / error states per house rules.

No new composable if `useParts` (or the parts view's existing composable)
already owns the section — extend it; otherwise one `usePartsCsvRoundTrip()`.

---

## 7. Manual drift to fix when this ships

§10.4a currently says rows are "matched on Part No." — that contradicts Q8
(the round-trip keys on `parts.id`; Part No. is the cross-check). Update
§10.4a on implementation: the download carries the Part No. **and** the
internal key; matching is on the internal key with the Part No. verified.

---

## 8. Tests this phase must carry

**Export:**
- Headers and column order exactly as §3; quantities plain decimal.
- Inactive parts included.
- Manager (if D4 = Admin-only) gets 403; Admin gets 200.

**Upload:**
- Round-trip: download → re-upload unmodified → all rows `unchanged`, zero
  drift.
- Corrected quantities applied wholesale; untouched parts unchanged.
- 422 with line-numbered errors and **nothing written** for: unknown
  `part_id`; duplicate `part_id`; `erp_part_code` mismatch; negative,
  non-numeric, and >3-decimal quantities; missing header; empty file;
  over-cap file.
- Extra columns in the file are ignored (upload the full download).
- Concurrency: upload and `RecordWorkOrderPart` for the same part serialise
  via the row lock — no lost update.
- Audit: one `parts.quantity_upload.completed` event with correct metadata;
  a rejected upload audits nothing.
- Auth: Manager/Technician/Logistics/Requester all 403.

**Guard:**
- After a successful upload, running `SyncParts` (when configured) still
  overwrites the quantities — the interim-warning promise holds.

---

## 9. Explicitly not in this phase

- **Any write-back to the ERP.** ATMS never writes quantities upstream.
- **Name/status/category/size corrections** (D1) — `ImportPartsCommand`
  remains the tool for those, deliberately CLI-only.
- **A live ERP quantity feed.** That is the 🟠 trigger; when it lands, this
  upload's reason to exist ends and it can be retired without data loss.
- **Re-keying anything.** ERP sync keeps matching on `erp_part_id`; nothing
  in this phase touches that.
