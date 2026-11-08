<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidSizeFormatException;
use App\Models\Asset;
use App\Services\Audit\AuditLogger;
use App\Support\Size;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring asset names and sizes in line with the cleaned LDC workbook.
 *
 * Exists because a database seeded from the ERP carries ERP descriptions as
 * names and no size at all, while `atms:import-assets` — which would fix both —
 * also overwrites status, serial number, model and tag. This writes `name` and
 * `size_inches` and nothing else.
 *
 * Matches on the immutable `erp_asset_code`. A blank workbook cell never clears
 * a value, and assets absent from the workbook are reported and left alone.
 */
#[Signature('atms:sync-asset-names {file? : Path to the assets CSV} {--dry-run : List every change, write nothing}')]
#[Description('Update asset name and size from the LDC workbook, matching on erp_asset_code.')]
class SyncAssetNamesCommand extends Command
{
    private const REQUIRED_HEADERS = ['erp_asset_code', 'name', 'size'];

    public function handle(AuditLogger $audit): int
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

        $assets = Asset::query()->get(['id', 'erp_asset_code', 'name', 'size_inches'])->keyBy('erp_asset_code');

        $errors = [];
        $changes = [];
        $unmatched = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $code = trim($row['erp_asset_code']);

            if ($code === '') {
                $errors[] = "line {$line}: erp_asset_code is blank.";

                continue;
            }

            if (isset($seen[$code])) {
                $errors[] = "line {$line}: erp_asset_code {$code} repeats line {$seen[$code]}.";

                continue;
            }
            $seen[$code] = $line;

            $asset = $assets->get($code);

            if ($asset === null) {
                $unmatched[] = $code;

                continue;
            }

            try {
                $size = Size::fromWorkbookCell($row['size']);
            } catch (InvalidSizeFormatException $e) {
                $errors[] = "line {$line}: {$code} size [{$e->rawValue}] — {$e->reason()}";

                continue;
            }

            $name = trim($row['name']);
            $change = [];

            if ($name !== '' && $name !== $asset->name) {
                $change['name'] = [$asset->name, $name];
            }

            if ($size !== null && ! $size->equals($asset->size_inches)) {
                $change['size_inches'] = [$asset->size_inches?->format(), $size->format(), $size->canonical()];
            }

            if ($change !== []) {
                $changes[] = ['asset' => $asset, 'code' => $code, 'change' => $change];
            }
        }

        if ($errors !== []) {
            $this->error(count($errors).' validation error(s) — nothing was written.');
            foreach (array_slice($errors, 0, 40) as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $workbookCodes = array_keys($seen);
        $notInWorkbook = $assets->keys()->reject(fn ($code) => in_array($code, $workbookCodes, true));

        $this->newLine();
        $this->table(['Summary', ''], [
            ['Workbook rows', count($rows)],
            ['Assets to change', count($changes)],
            ['  · names', count(array_filter($changes, fn ($c) => isset($c['change']['name'])))],
            ['  · sizes', count(array_filter($changes, fn ($c) => isset($c['change']['size_inches'])))],
            ['Workbook rows with no matching asset', count($unmatched)],
            ['Assets not in the workbook (untouched)', $notInWorkbook->count()],
        ]);

        foreach ($changes as $item) {
            foreach ($item['change'] as $field => $values) {
                $label = $field === 'name' ? 'name' : 'size';
                $this->line(sprintf('  %-9s %-5s [%s] → [%s]', $item['code'], $label, $values[0] ?? '—', $values[1]));
            }
        }

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('No matching asset for: '.implode(', ', array_slice($unmatched, 0, 20)).(count($unmatched) > 20 ? ' …' : ''));
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($changes, $audit) {
            foreach ($changes as $item) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($item['asset']->id);
                $before = [];
                $after = [];

                if (isset($item['change']['name'])) {
                    $before['name'] = $asset->name;
                    $asset->name = $item['change']['name'][1];
                    $after['name'] = $asset->name;
                }

                if (isset($item['change']['size_inches'])) {
                    $before['size_inches'] = $asset->size_inches?->canonical();
                    $asset->size_inches = Size::fromCanonical($item['change']['size_inches'][2]);
                    $after['size_inches'] = $item['change']['size_inches'][2];
                }

                $asset->save();
                $audit->log('asset.workbook_sync', $asset, $before, $after);
            }
        });

        $this->newLine();
        $this->info(count($changes).' asset(s) updated.');

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
        $missing = array_diff(self::REQUIRED_HEADERS, $headers);

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
}
