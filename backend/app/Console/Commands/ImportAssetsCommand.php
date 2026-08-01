<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidSizeFormatException;
use App\Jobs\ReconcilePmCategoryAssignmentsJob;
use App\Models\Asset;
use App\Models\FaSubclassTypeCode;
use App\Models\MaintenanceCategory;
use App\Models\PmRule;
use App\Support\Size;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Controlled import of the cleaned LDC asset workbook.
 *
 * Matches rows to existing assets on the immutable `erp_asset_code` — never on
 * a mutable name — and updates them in place. The whole file is validated
 * before anything is written; a single error rejects the entire import.
 *
 * Deliberately NOT destructive: assets absent from the workbook are reported
 * and left alone. Deleting them cascades to maintenance requests, work orders,
 * location history, readings and PM assignments, so that belongs in the
 * separate pre-go-live reset where it can be confirmed on its own.
 */
#[Signature('atms:import-assets {file? : Path to the assets CSV} {--dry-run : Validate and report only, write nothing} {--prune : Also DELETE assets absent from the workbook, cascading to their maintenance records}')]
#[Description('Validate and import the cleaned LDC assets workbook, matching on erp_asset_code.')]
class ImportAssetsCommand extends Command
{
    private const EXPECTED_HEADERS = [
        'asset_tag', 'erp_asset_code', 'name', 'maintenance_category', 'asset_kind',
        'serial_number', 'size', 'model', 'manufacturer_code', 'fa_subclass_code',
        'operational_status', 'maintenance_status',
    ];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * FA subclass codes the workbook carries that the lookup table has not seen
     * yet, mapped to the line that introduced them.
     *
     * @var array<string, int>
     */
    private array $newSubclasses = [];

    public function handle(): int
    {
        $path = $this->argument('file') ?? database_path('data/assets.csv');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

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

        $prepared = $this->validate($rows);

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
        $headers = fgetcsv($handle);

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
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($headers, array_pad($row, count($headers), ''));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Validate every row against the database and the Size grammar.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function validate(array $rows): array
    {
        $knownSubclasses = FaSubclassTypeCode::pluck('fa_subclass_code')->all();
        $existing = Asset::pluck('id', 'erp_asset_code');

        $seenCodes = [];
        $seenTags = [];
        $prepared = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2; // header is line 1
            $code = trim($row['erp_asset_code']);
            $tag = trim($row['asset_tag']);

            if ($code === '') {
                $this->errors[] = "line {$line}: erp_asset_code is blank.";

                continue;
            }

            if (isset($seenCodes[$code])) {
                $this->errors[] = "line {$line}: duplicate erp_asset_code {$code} (also line {$seenCodes[$code]}).";

                continue;
            }
            $seenCodes[$code] = $line;

            // Asset Tag: a missing tag warns, a duplicate or oversized tag fails.
            if ($tag === '') {
                $this->warn("line {$line}: {$code} has no asset_tag — leaving the existing value.");
            } else {
                if (isset($seenTags[$tag])) {
                    $this->errors[] = "line {$line}: duplicate asset_tag {$tag} (also line {$seenTags[$tag]}).";
                }
                if (mb_strlen($tag) > 15) {
                    $this->errors[] = "line {$line}: asset_tag {$tag} exceeds 15 characters.";
                }
                $seenTags[$tag] = $line;
            }

            if (! isset($existing[$code])) {
                $this->errors[] = "line {$line}: erp_asset_code {$code} does not match any existing asset.";

                continue;
            }

            $size = null;
            try {
                $size = Size::fromWorkbookCell($row['size']);
            } catch (InvalidSizeFormatException $e) {
                $this->errors[] = "line {$line}: {$code} size [{$e->rawValue}] — {$e->reason()}";
            }

            // An unknown subclass used to fail the row. The lookup table mirrors
            // ERP's vocabulary rather than governing it — ATMS has no standing
            // to reject a code the ERP already uses, and the admin screen that
            // was the only way to add one is gone. Unseen codes are recorded.
            $subclass = trim($row['fa_subclass_code']);
            if ($subclass !== '' && ! in_array($subclass, $knownSubclasses, true)) {
                $knownSubclasses[] = $subclass;
                $this->newSubclasses[$subclass] = $line;
            }

            foreach ([
                'asset_kind' => ['asset', 'package', 'component'],
                'operational_status' => ['active', 'under_maintenance', 'down', 'inactive'],
                'maintenance_status' => ['enrolled', 'withdrawn'],
            ] as $field => $allowed) {
                $value = trim($row[$field]);
                if ($value !== '' && ! in_array($value, $allowed, true)) {
                    $this->errors[] = "line {$line}: {$code} invalid {$field} [{$value}].";
                }
            }

            $category = trim($row['maintenance_category']);
            $categoryCode = $category === '' ? '' : MaintenanceCategory::codeFor($category);
            if ($category !== '' && ($categoryCode === '' || mb_strlen($categoryCode) > 50)) {
                $this->errors[] = "line {$line}: {$code} Maintenance Category cannot produce a valid code of 50 characters or fewer.";
            }

            $prepared[] = [
                'line' => $line,
                'asset_id' => $existing[$code],
                'erp_asset_code' => $code,
                'asset_tag' => $tag,
                'name' => trim($row['name']),
                'category' => $category,
                'size' => $size,
                'serial_number' => trim($row['serial_number']),
                'model' => trim($row['model']),
                'manufacturer_code' => trim($row['manufacturer_code']),
                'fa_subclass_code' => $subclass,
                'asset_kind' => trim($row['asset_kind']),
                'operational_status' => trim($row['operational_status']),
                'maintenance_status' => trim($row['maintenance_status']),
            ];
        }

        return $prepared;
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function reportPlan(array $prepared): void
    {
        $categories = collect($prepared)->pluck('category')->filter()->unique()->sort()->values();
        $matched = collect($prepared)->pluck('erp_asset_code');
        $untouched = Asset::whereNotIn('erp_asset_code', $matched->all())->count();

        $this->newLine();
        $this->table(['', 'Count'], [
            ['Assets to update', count($prepared)],
            ['Maintenance categories', $categories->count()],
            ['Rows with a size', collect($prepared)->whereNotNull('size')->count()],
            ['Rows without a size', collect($prepared)->whereNull('size')->count()],
            [
                'Existing assets NOT in the workbook',
                $untouched.($this->option('prune') ? ' — WILL BE DELETED' : ' (left untouched)'),
            ],
        ]);

        $this->line('Categories: '.$categories->implode(', '));

        if ($this->newSubclasses !== []) {
            $this->newLine();
            $this->warn('New FA subclass codes will be recorded with type code UNK:');
            foreach ($this->newSubclasses as $subclass => $line) {
                $this->line("  · {$subclass} (first seen on line {$line})");
            }
        }

        if ($this->option('prune') && $untouched > 0) {
            $this->newLine();
            $this->warn("--prune will DELETE {$untouched} asset(s) and cascade to their maintenance records:");

            foreach ($this->pruneTargets($matched->all()) as $target) {
                $this->line(sprintf(
                    '  %-10s %-14s %s',
                    $target->erp_asset_code,
                    $target->asset_tag ?? '—',
                    mb_strimwidth($target->name, 0, 60, '…'),
                ));
            }

            $counts = $this->cascadeCounts($matched->all());
            $this->line('  Cascade: '.collect($counts)->map(fn ($v, $k) => "{$k}={$v}")->implode('  '));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function applyImport(array $prepared): void
    {
        $updated = 0;
        $pruned = 0;

        DB::transaction(function () use ($prepared, &$updated, &$pruned) {
            $this->syncSubclasses();
            $categoryIds = $this->syncCategories($prepared);

            foreach ($prepared as $row) {
                $asset = Asset::find($row['asset_id']);

                $attributes = [
                    'name' => $row['name'],
                    'size_inches' => $row['size'],
                ];

                // A blank category used to clear the asset's category. It now
                // leaves the existing one alone, matching every other field
                // below: the workbook is not authoritative on values it does
                // not carry, and the column no longer accepts null anyway.
                if ($row['category'] !== '') {
                    $attributes['maintenance_category_id'] = $categoryIds[$row['category']];
                }

                // A blank cell means "leave what is there" for these — the
                // workbook is not authoritative on values it does not carry.
                foreach (['serial_number', 'model', 'fa_subclass_code', 'asset_kind', 'operational_status', 'maintenance_status'] as $field) {
                    if ($row[$field] !== '') {
                        $attributes[$field] = $row[$field];
                    }
                }

                // Vendor codes land in `manufacturer` until LDC supplies the
                // code -> name list, at which point they resolve to real names.
                if ($row['manufacturer_code'] !== '') {
                    $attributes['manufacturer'] = $row['manufacturer_code'];
                }

                if ($row['asset_tag'] !== '') {
                    $attributes['asset_tag'] = $row['asset_tag'];
                }

                $asset->fill($attributes)->save();
                $updated++;
            }

            // Pruning runs inside the same transaction as the update, so the
            // workbook either lands completely or not at all.
            if ($this->option('prune')) {
                $codes = collect($prepared)->pluck('erp_asset_code')->all();
                $pruned = Asset::whereNotIn('erp_asset_code', $codes)->delete();
            }
        });

        // Once for the whole workbook, never per row: a 400-row import would
        // otherwise queue 400 reconciliations that each re-walk the same rules.
        $this->reconcilePmCategories();

        $this->newLine();
        $this->info("Imported. {$updated} assets updated, ".MaintenanceCategory::count().' maintenance categories present.');

        if ($pruned > 0) {
            $this->warn("Pruned. {$pruned} asset(s) absent from the workbook were deleted.");
        }
    }

    /**
     * Assets present in the database but absent from the workbook.
     *
     * @param  array<int, string>  $workbookCodes
     * @return Collection<int, Asset>
     */
    private function pruneTargets(array $workbookCodes): Collection
    {
        return Asset::whereNotIn('erp_asset_code', $workbookCodes)
            ->orderBy('erp_asset_code')
            ->get(['id', 'erp_asset_code', 'asset_tag', 'name']);
    }

    /**
     * What a prune would cascade to, so the operator sees it before agreeing.
     *
     * @param  array<int, string>  $workbookCodes
     * @return array<string, int>
     */
    private function cascadeCounts(array $workbookCodes): array
    {
        $ids = $this->pruneTargets($workbookCodes)->pluck('id');

        return [
            'maintenance_requests' => DB::table('maintenance_requests')->whereIn('asset_id', $ids)->count(),
            'work_orders' => DB::table('work_orders')->whereIn('asset_id', $ids)->count(),
            'location_history' => DB::table('asset_location_histories')->whereIn('asset_id', $ids)->count(),
            'meter_readings' => DB::table('asset_meter_readings')->whereIn('asset_id', $ids)->count(),
            'pm_assignments' => DB::table('asset_pm_assignments')->whereIn('asset_id', $ids)->count(),
        ];
    }

    /**
     * Create any Maintenance Category the workbook introduces.
     *
     * This is the controlled path by which the vocabulary changes — there is no
     * administration UI for it by design.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array<string, int>
     */
    /**
     * Re-expand every category-linked PM rule after the workbook has landed.
     *
     * The import moves assets between maintenance categories in bulk, which is
     * exactly what category-linked PM coverage follows — but one job per rule
     * at the end is the whole cost, regardless of how many rows moved.
     */
    private function reconcilePmCategories(): void
    {
        $ruleIds = PmRule::query()->whereHas('maintenanceCategories')->pluck('id');

        foreach ($ruleIds as $ruleId) {
            dispatch(ReconcilePmCategoryAssignmentsJob::forRule($ruleId));
        }

        if ($ruleIds->isNotEmpty()) {
            $this->line("Queued PM category reconciliation for {$ruleIds->count()} rule(s).");
        }
    }

    /**
     * Record FA subclass codes the workbook introduced.
     *
     * `type_code` is required by the schema and drives the middle segment of a
     * generated asset tag. ATMS has no way to know it for a code it has never
     * seen, so the row is created with the same `UNK` the tag service already
     * falls back to — visible as unknown rather than guessed.
     */
    private function syncSubclasses(): void
    {
        foreach (array_keys($this->newSubclasses) as $subclass) {
            FaSubclassTypeCode::firstOrCreate(
                ['fa_subclass_code' => $subclass],
                ['type_code' => 'UNK'],
            );
        }
    }

    private function syncCategories(array $prepared): array
    {
        $names = collect($prepared)->pluck('category')->filter()->unique();
        $ids = [];

        foreach ($names as $name) {
            $ids[$name] = MaintenanceCategory::firstOrCreate(
                ['code' => MaintenanceCategory::codeFor($name)],
                ['name' => $name, 'is_active' => true],
            )->id;
        }

        return $ids;
    }
}
