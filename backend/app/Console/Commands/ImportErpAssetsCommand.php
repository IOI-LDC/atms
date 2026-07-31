<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportErpAssetsCommand extends Command
{
    protected $signature = 'atms:import-erp-assets
        {file? : Path to the ERP assets CSV file (default: docs/sm/01-product/erp_assets_dump.csv)}
        {--force : Skip confirmation prompt}
        {--dry-run : Parse and validate only, do not insert}';

    protected $description = 'Truncate ATMS assets and import from ERP CSV dump';

    private array $stats = [
        'parsed' => 0,
        'skipped' => 0,
        'inserted' => 0,
        'errors' => [],
    ];

    private array $uniqueNames = [];

    public function handle(): int
    {
        $path = $this->argument('file') ?? base_path('../docs/sm/01-product/erp_assets_dump.csv');

        if (! file_exists($path)) {
            $this->error("CSV file not found: {$path}");

            return self::FAILURE;
        }

        $this->info("Reading: {$path}");

        // --- Parse CSV ---
        $rows = $this->parseCsv($path);
        $this->stats['parsed'] = count($rows);

        $this->info("Parsed {$this->stats['parsed']} assets from CSV.");

        if ($this->stats['parsed'] === 0) {
            $this->error('CSV is empty or has no valid rows.');

            return self::FAILURE;
        }

        // --- Dry run: show mapping and stop ---
        if ($this->option('dry-run')) {
            $this->showDryRun($rows);

            return self::SUCCESS;
        }

        // --- Confirm ---
        $existingCount = Asset::count();

        if (! $this->option('force')) {
            if ($existingCount > 0) {
                $this->warn("ATMS currently has {$existingCount} assets.");
            }

            if (! $this->confirm('Truncate assets and import from CSV? This cannot be undone.')) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        // --- Truncate ---
        $this->truncateAssets();

        // --- Insert ---
        $this->importAssets($rows);

        // --- Report ---
        $this->reportResults();

        return self::SUCCESS;
    }

    private function parseCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }

        // Normalize headers (trim whitespace)
        $headers = array_map('trim', $headers);

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < count($headers)) {
                continue; // skip malformed rows
            }

            $row = array_combine($headers, $data);
            $mapped = $this->mapRow($row);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function mapRow(array $row): ?array
    {
        $erpNo = trim($row['no'] ?? '');

        if (empty($erpNo)) {
            $this->stats['errors'][] = "Skipped row: missing 'no' field.";

            return null;
        }

        $name = trim($row['description'] ?? '');
        $serialNo = trim($row['serialNo'] ?? '');

        // Ensure unique names by appending a suffix if needed
        $baseName = $name;
        $counter = 1;
        while (in_array($name, $this->uniqueNames, true)) {
            $counter++;
            $name = $baseName.' (#'.$counter.')';
        }
        $this->uniqueNames[] = $name;

        $underMaintenance = strtolower(trim($row['underMaintenance'] ?? '')) === 'true';
        $inactive = strtolower(trim($row['inactive'] ?? '')) === 'true';

        return [
            'erp_asset_code' => $erpNo,
            'name' => $name,
            'description' => $row['description'] ?? null,
            'serial_number' => $serialNo !== '' ? $serialNo : null,
            'fa_subclass_code' => trim($row['faSubclassCode'] ?? '') ?: null,
            'manufacturer' => trim($row['vendorNo'] ?? '') ?: null,
            'operational_status' => $underMaintenance ? 'under_maintenance' : 'active',
            'is_active' => ! $inactive,
            'maintenance_status' => 'enrolled',
            'asset_kind' => 'asset',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function truncateAssets(): void
    {
        $this->info('Truncating assets and related records...');

        // Delete child records in dependency order before truncating assets
        // Using delete() to avoid PostgreSQL FK issues with truncate
        $tables = [
            'asset_meter_readings',
            'asset_location_histories',
            'pm_occurrence_suppressions',
            'pm_rules',
            'attachments',
            'audit_logs',
            'maintenance_requests',
            'work_order_parts',
            'work_orders',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        // Now truncate assets itself
        Asset::truncate();

        $this->info('Truncate complete.');
    }

    private function importAssets(array $rows): void
    {
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $row) {
            try {
                Asset::create($row);
                $this->stats['inserted']++;
            } catch (\Exception $e) {
                $this->stats['errors'][] = "Failed to insert {$row['erp_asset_code']}: {$e->getMessage()}";
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
    }

    private function showDryRun(array $rows): void
    {
        $this->newLine();
        $this->info('Dry run — showing first 5 row mappings:');
        $this->newLine();

        $columns = ['erp_asset_code', 'name', 'description', 'serial_number', 'fa_subclass_code', 'manufacturer', 'operational_status', 'is_active'];

        $this->table($columns, array_map(function ($row) use ($columns) {
            return array_map(function ($col) use ($row) {
                $val = $row[$col] ?? '—';
                if (is_bool($val)) {
                    return $val ? 'true' : 'false';
                }
                $str = (string) $val;

                return strlen($str) > 60 ? substr($str, 0, 57).'...' : $str;
            }, $columns);
        }, array_slice($rows, 0, 5)));

        $this->newLine();
        $this->info("Total rows to import: {$this->stats['parsed']}");

        if ($this->stats['errors']) {
            $this->warn('Validation errors:');
            foreach (array_slice($this->stats['errors'], 0, 10) as $err) {
                $this->line("  · {$err}");
            }

            if (count($this->stats['errors']) > 10) {
                $this->line('  ... and '.count($this->stats['errors']).' more');
            }
        }
    }

    private function reportResults(): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════');
        $this->info(' Import Complete');
        $this->info('═══════════════════════════════════');
        $this->line("  Parsed:   {$this->stats['parsed']}");
        $this->line("  Inserted: {$this->stats['inserted']}");
        $this->line("  Skipped:  {$this->stats['skipped']}");
        $this->line('  Errors:   '.count($this->stats['errors']));

        $total = Asset::count();
        $withSerial = Asset::whereNotNull('serial_number')->count();
        $withFaSubclass = Asset::whereNotNull('fa_subclass_code')->count();

        $this->line("  DB total: {$total} assets");
        $this->line("  With serial:      {$withSerial}");
        $this->line("  With FA subclass: {$withFaSubclass}");

        if ($this->stats['errors']) {
            $this->newLine();
            $this->warn('Errors encountered:');
            foreach (array_slice($this->stats['errors'], 0, 10) as $err) {
                $this->line("  · {$err}");
            }

            if (count($this->stats['errors']) > 10) {
                $this->line('  ... and '.(count($this->stats['errors']) - 10).' more');
            }
        }
    }
}
