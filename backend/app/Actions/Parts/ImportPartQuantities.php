<?php

namespace App\Actions\Parts;

use App\Models\Part;
use App\Services\Audit\AuditLogger;
use App\Support\Reports\CsvReportStreamer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * RQ3 — apply corrected stock quantities from an uploaded CSV.
 *
 * The workflow this serves is offline: the team downloads the ATMS parts list,
 * VLOOKUPs the ERP's quantities onto it in Excel, and uploads the result. So
 * every rule here is written against the failure modes of a spreadsheet, not of
 * an API client.
 *
 * **All-or-nothing.** Every row is validated before the transaction opens, and
 * one bad row rejects the file. A half-applied stock correction is worse than a
 * rejected one: the operator cannot tell which half landed, and their own file
 * is the source of truth either way — they fix it and retry.
 *
 * **Keyed on `parts.id`, never on the part code** (Q8). `erp_part_code` is
 * carried for the operator to read and is cross-checked to catch a shifted
 * VLOOKUP, but it is nullable, LDC edit it, and nothing guarantees it unique.
 *
 * ⚠️ **Interim.** ERP remains the quantity authority; `SyncParts` overwrites
 * `available_quantity` wholesale once the live feed lands (🟠 D-020). This path
 * keeps the figure honest between refreshes and can be retired without data
 * loss when that happens.
 */
class ImportPartQuantities
{
    /** Well above the 734-part catalogue; cheap insurance against a runaway file. */
    private const MAX_ROWS = 25_000;

    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Matches `numeric(14,3)` exactly: 11 integer digits, 3 decimals, non-negative. */
    private const QUANTITY_PATTERN = '/^\d{1,11}(\.\d{1,3})?$/';

    private const REQUIRED_HEADERS = ['part_id', 'erp_part_code', 'available_quantity'];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @return array{rows: int, updated: int, unchanged: int}
     *
     * @throws DomainException with a newline-joined error list when the file is
     *                         rejected. The controller renders it as 422.
     */
    public function execute(UploadedFile $file, int $uploadedByUserId): array
    {
        $this->errors = [];

        if ($file->getSize() > self::MAX_BYTES) {
            throw new DomainException('The file is larger than 5 MB.');
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $rows = $this->read($file);

        // Checked before the empty-file message: a file whose every row was
        // rejected for a malformed shape is not an empty file, and saying so
        // would send the operator looking for the wrong problem.
        if ($this->errors === [] && $rows === []) {
            throw new DomainException('The file has no data rows.');
        }

        $prepared = $this->validateRows($rows);

        if ($this->errors !== []) {
            throw new DomainException(implode("\n", $this->errors));
        }

        return $this->apply($prepared, $file->getClientOriginalName(), $hash, $uploadedByUserId, count($rows));
    }

    /**
     * @return array<int, array{line: int, data: array<string, string>}>
     *
     * @throws DomainException on a structural problem — a missing header is not
     *                         a row error, it makes every row meaningless.
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        // `escape: ''` disables PHP's non-standard backslash escaping, which no
        // spreadsheet emits and which silently swallows a trailing backslash.
        // Also required on PHP 8.4, where the default is deprecated.
        // `ImportPartsCommand` already reads this way.
        $headers = fgetcsv($handle, escape: '');

        if ($headers === false) {
            fclose($handle);
            throw new DomainException('The file is empty.');
        }

        // Excel writes a UTF-8 BOM — including into files it opened from our own
        // export — and it lands invisibly on the first header, so `part_id`
        // stops matching for reasons nobody can see by looking at the file.
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);

        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

        if ($missing !== []) {
            fclose($handle);
            throw new DomainException('Missing required column(s): '.implode(', ', $missing).'.');
        }

        // A repeated header makes `array_combine` keep only the last of the
        // duplicates, so one of two columns would be read and the other
        // silently ignored. There is no safe interpretation — reject the file.
        $duplicates = array_keys(array_filter(array_count_values($headers), fn ($n) => $n > 1));

        if ($duplicates !== []) {
            fclose($handle);
            throw new DomainException('Duplicate column(s): '.implode(', ', $duplicates).'.');
        }

        $expected = count($headers);
        $rows = [];
        $line = 1;

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            // Blank rows are what Excel leaves behind after a delete; they are
            // not an operator error.
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new DomainException('The file has more than '.number_format(self::MAX_ROWS).' rows.');
            }

            // ⚠️ Never truncate or pad to fit. An earlier version sliced the row
            // to the header count, which turned an unquoted `1,200` in the
            // quantity column into two cells, discarded the `200`, and committed
            // a stock level of 1. A row whose shape does not match the header is
            // a row whose meaning is unknown; the operator is told which line.
            $actual = count($data);

            if ($actual !== $expected) {
                $this->errors[] = "line {$line}: expected {$expected} columns, found {$actual}. "
                    .'Check for an unquoted comma inside a value.';

                continue;
            }

            $rows[] = [
                'line' => $line,
                'data' => array_combine($headers, $data),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, array{line: int, data: array<string, string>}>  $rows
     * @return array<int, array{id: int, quantity: string}>
     */
    private function validateRows(array $rows): array
    {
        // One query for the whole file rather than one per row: a catalogue-wide
        // correction is 700+ rows and this runs before the transaction opens.
        $ids = collect($rows)
            ->pluck('data.part_id')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => ctype_digit($v))
            ->map(fn ($v) => (int) $v)
            ->unique();

        $known = Part::whereIn('id', $ids)->pluck('erp_part_code', 'id');

        $seen = [];
        $prepared = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $rawId = trim((string) $row['data']['part_id']);
            $code = $this->unguard(trim((string) $row['data']['erp_part_code']));
            $quantity = trim((string) $row['data']['available_quantity']);

            if ($rawId === '' || ! ctype_digit($rawId)) {
                $this->errors[] = "line {$line}: part_id is blank or not a number [{$rawId}].";

                continue;
            }

            $id = (int) $rawId;

            if (isset($seen[$id])) {
                $this->errors[] = "line {$line}: duplicate part_id {$id} (also line {$seen[$id]}).";

                continue;
            }
            $seen[$id] = $line;

            if (! $known->has($id)) {
                // Never insert. This file corrects a catalogue; it does not
                // extend one — that is the ERP's job.
                $this->errors[] = "line {$line}: part_id {$id} does not match any existing part.";

                continue;
            }

            // The shifted-VLOOKUP catch. A blank cell skips it: the operator may
            // simply not have carried the column, which is not evidence of a
            // shift and must not reject an otherwise-correct file. A filled cell
            // against a part with no code IS the shift, so it fails.
            $stored = $known->get($id);

            if ($code !== '' && strcasecmp($code, (string) $stored) !== 0) {
                $shown = $stored === null || $stored === '' ? '(none)' : $stored;
                $this->errors[] = "line {$line}: part_id {$id} has erp_part_code [{$shown}] in ATMS; the file says [{$code}].";

                continue;
            }

            if (! preg_match(self::QUANTITY_PATTERN, $quantity)) {
                $label = $stored ?: (string) $id;
                $this->errors[] = "line {$line}: {$label} has an invalid available_quantity [{$quantity}] — "
                    .'it must be zero or more, with at most three decimal places.';

                continue;
            }

            $prepared[] = ['id' => $id, 'quantity' => $quantity];
        }

        return $prepared;
    }

    /**
     * Undo the export's formula guard.
     *
     * `CsvReportStreamer` prefixes a cell that starts `=`, `+`, `-`, `@` or a
     * control character with an apostrophe, so a spreadsheet reads it as text
     * instead of executing it. A part code like `-1036-LDC` therefore leaves
     * ATMS as `'-1036-LDC` — and would come straight back failing the
     * shifted-VLOOKUP cross-check against the very value that produced it.
     *
     * Only the leading apostrophe is removed, and only when what follows would
     * have been guarded. A code that genuinely begins with an apostrophe and
     * nothing else is left alone.
     *
     * @see CsvReportStreamer::neutralise()
     */
    private function unguard(string $value): string
    {
        if (! str_starts_with($value, "'") || $value === "'") {
            return $value;
        }

        $rest = substr($value, 1);

        return CsvReportStreamer::neutralise($rest) === $value ? $rest : $value;
    }

    /**
     * @param  array<int, array{id: int, quantity: string}>  $prepared
     * @return array{rows: int, updated: int, unchanged: int}
     */
    private function apply(array $prepared, string $filename, string $hash, int $uploadedByUserId, int $rowCount): array
    {
        // Locked in ascending id order, never in the order the spreadsheet
        // happened to list them. Two operators uploading files sorted
        // differently would otherwise take the same row locks in opposite
        // orders and deadlock — one request dying with an uncaught 500 on a
        // stock correction that was perfectly valid.
        usort($prepared, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return DB::transaction(function () use ($prepared, $filename, $hash, $uploadedByUserId, $rowCount) {
            $updated = 0;
            $unchanged = 0;

            foreach ($prepared as $row) {
                // Same lock RecordWorkOrderPart takes, so a stock correction and
                // a work-order consumption serialise rather than clobbering one
                // another.
                $part = Part::where('id', $row['id'])->lockForUpdate()->first();

                // String assignment against the decimal cast: `'12.5'` and a
                // stored `12.500` are the same number, and Eloquent's numeric
                // comparison treats them as such — so a re-uploaded, unedited
                // download reports every row unchanged rather than every row
                // corrected.
                $part->available_quantity = $row['quantity'];

                if ($part->isDirty('available_quantity')) {
                    $part->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            // One event, not one per row: a catalogue-wide correction is 700+
            // rows and per-row entries would bury everything else in the log.
            // The file hash is the provenance — it ties this summary to the
            // exact spreadsheet the operator uploaded.
            app(AuditLogger::class)->log('parts.quantity_upload.completed', null, [], [], [
                'filename' => $filename,
                'file_sha256' => $hash,
                'rows' => $rowCount,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'uploaded_by_user_id' => $uploadedByUserId,
            ]);

            return ['rows' => $rowCount, 'updated' => $updated, 'unchanged' => $unchanged];
        });
    }
}
