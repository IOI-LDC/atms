<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidSizeFormatException;
use App\Models\MaintenanceCategory;
use App\Models\Part;
use App\Support\Size;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Controlled update of the cleaned LDC parts workbook.
 *
 * Rows match existing parts on the immutable ERP System ID. The ERP part code
 * is cross-checked as a safeguard against matching the wrong part; when the
 * system id matches but the code differs, the code is treated as an ERP rename
 * and updated. Rows whose system id is unknown (or absent) are inserted as new
 * parts, keyed on the unique ERP part code, so new items from the ERP export
 * can be adopted. This command never deletes parts. Every row is validated
 * before the transaction begins.
 */
#[Signature('atms:import-parts {file? : Path to the parts migration CSV} {--dry-run : Validate and report only, write nothing}')]
#[Description('Validate and update existing parts from the cleaned LDC parts workbook.')]
class ImportPartsCommand extends Command
{
    private const EXPECTED_HEADERS = [
        'erp_system_id',
        'erp_part_code',
        'cleaned_name',
        'available_quantity',
        'status',
        'is_active',
        'proposed_maintenance_category_name',
        'proposed_size',
        'proposed_size_inches',
        'proposed_part_number',
        'requires_review',
    ];

    /** @var array<int, string> */
    private array $errors = [];

    public function handle(): int
    {
        $this->errors = [];
        $path = $this->argument('file') ?? database_path('data/parts-migration-source.csv');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or unreadable: {$path}");

            return self::FAILURE;
        }

        $this->info("Workbook: {$path}");
        $this->line('SHA-256:  '.hash_file('sha256', $path));

        $rows = $this->readCsv($path);

        if ($rows === null) {
            return self::FAILURE;
        }

        $this->line('Rows:     '.count($rows));
        $this->newLine();

        $prepared = $this->validateRows($rows);

        if ($this->errors !== []) {
            $this->error(count($this->errors).' validation error(s) — nothing was written.');
            foreach (array_slice($this->errors, 0, 40) as $error) {
                $this->line("  {$error}");
            }
            if (count($this->errors) > 40) {
                $this->line('  … '.(count($this->errors) - 40).' more.');
            }

            return self::FAILURE;
        }

        $this->info('Validation passed.');
        $this->reportPlan($prepared);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        $this->applyImport($prepared);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string>>|null
     */
    private function readCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error("Unable to open workbook: {$path}");

            return null;
        }

        $headers = fgetcsv($handle, escape: '');

        if ($headers === false) {
            $this->error('The workbook is empty.');
            fclose($handle);

            return null;
        }

        $headers = array_map(trim(...), $headers);
        $missing = array_diff(self::EXPECTED_HEADERS, $headers);

        if ($missing !== []) {
            $this->error('Missing column(s): '.implode(', ', $missing));
            fclose($handle);

            return null;
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            $line++;

            if (count(array_filter($values, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }

            if (count($values) !== count($headers)) {
                $this->errors[] = "line {$line}: expected ".count($headers).' columns, found '.count($values).'.';

                continue;
            }

            $row = array_combine($headers, $values);

            if ($row !== false) {
                $row['_line'] = (string) $line;
                $rows[] = $row;
            }
        }

        fclose($handle);

        if ($rows === []) {
            $this->error('The workbook has no data rows.');

            return null;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function validateRows(array $rows): array
    {
        $existingByErpId = Part::query()
            ->whereNotNull('erp_part_id')
            ->get(['id', 'erp_part_id', 'erp_part_code'])
            ->keyBy('erp_part_id');
        $existingByCode = Part::query()
            ->get(['id', 'erp_part_id', 'erp_part_code'])
            ->keyBy('erp_part_code');
        $seenErpIds = [];
        $seenErpCodes = [];
        $categoryNamesByCode = [];
        $prepared = [];

        foreach ($rows as $row) {
            $line = (int) $row['_line'];
            $erpId = trim($row['erp_system_id']);
            $erpCode = trim($row['erp_part_code']);
            $name = trim($row['cleaned_name']);
            $categoryName = trim($row['proposed_maintenance_category_name']);
            $partNumber = trim($row['proposed_part_number']);

            if ($erpCode === '') {
                $this->errors[] = "line {$line}: erp_part_code is blank.";

                continue;
            }

            if (isset($seenErpCodes[$erpCode])) {
                $this->errors[] = "line {$line}: duplicate erp_part_code {$erpCode} (also line {$seenErpCodes[$erpCode]}).";

                continue;
            }
            $seenErpCodes[$erpCode] = $line;

            if ($erpId !== '') {
                if (isset($seenErpIds[$erpId])) {
                    $this->errors[] = "line {$line}: duplicate erp_system_id {$erpId} (also line {$seenErpIds[$erpId]}).";

                    continue;
                }
                $seenErpIds[$erpId] = $line;
            }

            /** @var Part|null $part */
            $part = $erpId === '' ? null : $existingByErpId->get($erpId);

            if ($part === null) {
                // A code already in the database only resolves back to that part
                // when it is the same item: the ERP id matches, or the stored
                // part has no ERP id yet (previously inserted from this workbook).
                $byCode = $existingByCode->get($erpCode);

                if ($byCode !== null && $byCode->erp_part_id !== null) {
                    $this->errors[] = "line {$line}: erp_part_code {$erpCode} is already assigned to ERP item [{$byCode->erp_part_id}].";
                } elseif ($byCode !== null) {
                    $part = $byCode;
                }
            }

            if ($part !== null && $part->erp_part_code !== $erpCode) {
                $byCode = $existingByCode->get($erpCode);

                if ($byCode !== null && $byCode->id !== $part->id) {
                    $this->errors[] = "line {$line}: erp_part_code {$erpCode} is already assigned to ERP item [{$byCode->erp_part_id}].";
                }
                // else: the ERP renamed the code — the immutable system id matched,
                // so the new code is applied when the import runs.
            }

            if ($name === '') {
                $this->errors[] = "line {$line}: {$erpCode} cleaned_name is blank.";
            } elseif (mb_strlen($name) > 255) {
                $this->errors[] = "line {$line}: {$erpCode} cleaned_name exceeds 255 characters.";
            }

            $quantity = $this->validateQuantity($row['available_quantity'], $line, $erpCode);
            $status = strtolower(trim($row['status']));
            $isActive = $this->parseBoolean($row['is_active']);

            if (! in_array($status, ['active', 'inactive'], true)) {
                $this->errors[] = "line {$line}: {$erpCode} invalid status [{$row['status']}].";
            }

            if ($isActive === null) {
                $this->errors[] = "line {$line}: {$erpCode} invalid is_active [{$row['is_active']}].";
            } elseif (in_array($status, ['active', 'inactive'], true) && $isActive !== ($status === 'active')) {
                $this->errors[] = "line {$line}: {$erpCode} status and is_active disagree.";
            }

            $requiresReview = $this->parseBoolean($row['requires_review']);
            if ($requiresReview !== false) {
                $this->errors[] = "line {$line}: {$erpCode} requires_review must be false.";
            }

            $size = $this->validateSize($row, $line, $erpCode);

            if (mb_strlen($partNumber) > 255) {
                $this->errors[] = "line {$line}: {$erpCode} proposed_part_number exceeds 255 characters.";
            }

            $categoryCode = $categoryName === '' ? '' : MaintenanceCategory::codeFor($categoryName);
            if ($categoryName !== '' && ($categoryCode === '' || mb_strlen($categoryCode) > 50)) {
                $this->errors[] = "line {$line}: {$erpCode} Maintenance Category cannot produce a valid code of 50 characters or fewer.";
            } elseif ($categoryCode !== '') {
                if (isset($categoryNamesByCode[$categoryCode]) && $categoryNamesByCode[$categoryCode] !== $categoryName) {
                    $this->errors[] = "line {$line}: Maintenance Category code {$categoryCode} maps to both [{$categoryNamesByCode[$categoryCode]}] and [{$categoryName}].";
                }
                $categoryNamesByCode[$categoryCode] = $categoryName;
            }

            $prepared[] = [
                'part_id' => $part?->id,
                'is_new' => $part === null,
                'erp_part_id' => $erpId === '' ? null : $erpId,
                'erp_part_code' => $erpCode,
                'name' => $name,
                'unit_of_measure' => trim($row['unit_of_measure'] ?? '') ?: 'EA',
                'available_quantity' => $quantity,
                'erp_status' => $status,
                'is_active' => $isActive,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'size' => $size,
                'part_number' => $partNumber === '' ? null : $partNumber,
            ];
        }

        return $prepared;
    }

    private function validateQuantity(string $raw, int $line, string $erpCode): ?string
    {
        $value = trim($raw);

        if (! preg_match('/^-?\d{1,11}(?:\.\d{1,3})?$/', $value)) {
            $this->errors[] = "line {$line}: {$erpCode} invalid available_quantity [{$raw}].";

            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function validateSize(array $row, int $line, string $erpCode): ?Size
    {
        $formatted = trim($row['proposed_size']);
        $canonical = trim($row['proposed_size_inches']);

        if ($formatted === '' && $canonical === '') {
            return null;
        }

        if ($formatted === '' || $canonical === '') {
            $this->errors[] = "line {$line}: {$erpCode} proposed_size and proposed_size_inches must both be populated or both be blank.";

            return null;
        }

        try {
            $size = Size::fromWorkbookCell($formatted);
            $canonicalSize = Size::fromWorkbookCell($canonical);
        } catch (InvalidSizeFormatException $exception) {
            $this->errors[] = "line {$line}: {$erpCode} size [{$exception->rawValue}] — {$exception->reason()}";

            return null;
        }

        if ($size === null || ! $size->equals($canonicalSize)) {
            $this->errors[] = "line {$line}: {$erpCode} proposed_size [{$formatted}] does not equal proposed_size_inches [{$canonical}].";

            return null;
        }

        return $size;
    }

    private function parseBoolean(string $raw): ?bool
    {
        return match (strtolower(trim($raw))) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function reportPlan(array $prepared): void
    {
        $categories = collect($prepared)->pluck('category_name')->filter()->unique()->sort()->values();
        $partIds = collect($prepared)->pluck('part_id')->filter();
        $untouched = Part::whereNotIn('id', $partIds->all())->count();
        $newCount = count($prepared) - $partIds->count();

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Parts to update', $partIds->count()],
            ['New parts to create', $newCount],
            ['Maintenance categories', $categories->count()],
            ['Rows with a size', collect($prepared)->whereNotNull('size')->count()],
            ['Rows without a size', collect($prepared)->whereNull('size')->count()],
            ['Existing parts NOT in the workbook', $untouched.' (left untouched)'],
        ]);

        $this->line('Categories: '.$categories->implode(', '));
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function applyImport(array $prepared): void
    {
        $updated = 0;
        $unchanged = 0;
        $created = 0;

        DB::transaction(function () use ($prepared, &$updated, &$unchanged, &$created): void {
            $categoryIds = $this->syncCategories($prepared);
            $parts = Part::whereKey(collect($prepared)->pluck('part_id')->filter())
                ->get()
                ->keyBy('id');

            foreach ($prepared as $row) {
                if ($row['is_new']) {
                    Part::create([
                        'erp_part_id' => $row['erp_part_id'],
                        'erp_part_code' => $row['erp_part_code'],
                        'part_number' => $row['part_number'],
                        'name' => $row['name'],
                        'unit_of_measure' => $row['unit_of_measure'],
                        'available_quantity' => $row['available_quantity'],
                        'erp_status' => $row['erp_status'],
                        'is_active' => $row['is_active'],
                        'maintenance_category_id' => $row['category_code'] === ''
                            ? null
                            : $categoryIds[$row['category_code']],
                        'size_inches' => $row['size'],
                    ]);
                    $created++;

                    continue;
                }

                /** @var Part $part */
                $part = $parts->get($row['part_id']);

                if ($part->erp_part_id === null && $row['erp_part_id'] !== null) {
                    $part->erp_part_id = $row['erp_part_id'];
                }

                $part->fill([
                    'erp_part_code' => $row['erp_part_code'],
                    'name' => $row['name'],
                    'unit_of_measure' => $row['unit_of_measure'],
                    'available_quantity' => $row['available_quantity'],
                    'erp_status' => $row['erp_status'],
                    'is_active' => $row['is_active'],
                    'maintenance_category_id' => $row['category_code'] === ''
                        ? null
                        : $categoryIds[$row['category_code']],
                    'size_inches' => $row['size'],
                    'part_number' => $row['part_number'],
                ]);

                if ($part->isDirty()) {
                    $part->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }
        });

        $this->newLine();
        $this->info("Imported. {$updated} parts updated, {$unchanged} unchanged, {$created} created, ".MaintenanceCategory::count().' maintenance categories present.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array<string, int>
     */
    private function syncCategories(array $prepared): array
    {
        $categories = collect($prepared)
            ->filter(fn (array $row): bool => $row['category_code'] !== '')
            ->unique('category_code')
            ->map(fn (array $row): array => [
                'code' => $row['category_code'],
                'name' => $row['category_name'],
            ])
            ->values();

        $existing = MaintenanceCategory::whereIn('code', $categories->pluck('code'))
            ->get()
            ->keyBy('code');

        foreach ($categories as $category) {
            $model = $existing->get($category['code']);

            if ($model === null) {
                $model = MaintenanceCategory::create([
                    ...$category,
                    'is_active' => true,
                ]);
                $existing->put($category['code'], $model);

                continue;
            }

            $model->fill([
                'name' => $category['name'],
                'is_active' => true,
            ]);

            if ($model->isDirty()) {
                $model->save();
            }
        }

        return $existing->pluck('id', 'code')->all();
    }
}
