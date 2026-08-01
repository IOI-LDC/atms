<?php

namespace App\Support\Reports;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report as CSV.
 *
 * Every report exports through this one class so the 19 report endpoints cannot
 * drift in encoding, date format, or boolean spelling. It is deliberately dumb:
 * callers supply an ordered column map and a row source, and get back a
 * StreamedResponse that writes as it goes.
 *
 * Three decisions are baked in because they are wrong to leave per-report:
 *
 * 1. **A UTF-8 BOM is written first.** Excel assumes the system codepage for a
 *    BOM-less CSV, which turns Arabic asset and location names into mojibake.
 *    The BOM costs three bytes and is ignored by everything else.
 * 2. **Timestamps render in the company timezone, not UTC.** A person opens
 *    this file, and the SPA shows them Africa/Tripoli everywhere else; an
 *    export that silently shifts by two hours is a support ticket.
 * 3. **Rows are streamed, never collected.** Callers hand over a LazyCollection
 *    or generator for unbounded listings, so memory stays flat regardless of
 *    how many assets LDC accumulates.
 */
class CsvReportStreamer
{
    /** Excel needs this to read the file as UTF-8. */
    private const BOM = "\xEF\xBB\xBF";

    /** Flush to the client every N rows so large exports start downloading immediately. */
    private const FLUSH_EVERY = 500;

    /**
     * @param  string  $slug  Report slug; becomes `{slug}-{YYYY-MM-DD}.csv`.
     * @param  array<string, string|Closure>  $columns  Ordered header label => dot path or extractor.
     * @param  iterable<mixed>  $rows
     */
    public function stream(string $slug, array $columns, iterable $rows): StreamedResponse
    {
        $filename = sprintf('%s-%s.csv', $slug, $this->now()->format('Y-m-d'));

        return new StreamedResponse(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'w');

            echo self::BOM;
            fputcsv($handle, array_keys($columns));

            $written = 0;

            foreach ($rows as $row) {
                fputcsv($handle, $this->line($row, $columns));

                if (++$written % self::FLUSH_EVERY === 0) {
                    flush();
                }
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            // Exports reflect live data; a cached copy is always wrong.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            // Lets the SPA read the filename back off a fetch() response.
            'Access-Control-Expose-Headers' => 'Content-Disposition',
        ]);
    }

    /**
     * @param  array<string, string|Closure>  $columns
     * @return array<int, string>
     */
    private function line(mixed $row, array $columns): array
    {
        $line = [];

        foreach ($columns as $accessor) {
            $line[] = $this->format(
                $accessor instanceof Closure ? $accessor($row) : data_get($row, $accessor)
            );
        }

        return $line;
    }

    /**
     * Render one cell. Spreadsheets have no concept of null or enum, so every
     * value collapses to a string here rather than in 19 column maps.
     */
    private function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => Carbon::instance($value)
                ->setTimezone($this->timezone())
                ->format('Y-m-d H:i'),
            is_array($value) => implode(', ', array_map(fn ($v) => $this->format($v), $value)),
            default => (string) $value,
        };
    }

    private function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    private function timezone(): string
    {
        return config('atms.company_timezone', 'UTC');
    }
}
