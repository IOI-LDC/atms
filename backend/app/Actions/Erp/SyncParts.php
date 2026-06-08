<?php

namespace App\Actions\Erp;

use App\Contracts\Erp\ErpSource;
use App\Models\ErpSyncJob;
use App\Models\Part;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SyncParts
{
    public function __construct(
        private ErpSource $source
    ) {}

    public function execute(?int $triggeredByUserId = null): ErpSyncJob
    {
        $logger = app(AuditLogger::class);
        $beforeJob = [];
        $job = ErpSyncJob::create([
            'sync_type' => 'parts',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredByUserId,
        ]);
        $logger->log('sync_parts_started', $job, $beforeJob, $job->toArray());

        try {
            $cursor = null;
            $hasMore = true;

            $lastSyncRaw = Part::whereNotNull('erp_last_synced_at')
                ->orderByRaw('erp_last_synced_at ASC')
                ->value('erp_last_synced_at');
            $lastSync = $lastSyncRaw ? Carbon::parse($lastSyncRaw)->toIso8601String() : null;

            $totalRecords = 0;
            $createdCount = 0;
            $updatedCount = 0;
            $failedCount = 0;

            while ($hasMore) {
                $result = $this->source->getParts($lastSync, $cursor);

                foreach ($result['data'] as $external) {
                    $totalRecords++;

                    try {
                        DB::transaction(function () use ($external, &$createdCount, &$updatedCount) {
                            $part = Part::firstOrNew(['erp_part_id' => (string) $external->id]);

                            $isNew = ! $part->exists;

                            $part->fill([
                                'erp_part_code' => $external->code,
                                'name' => $external->name,
                                'description' => $external->description,
                                'unit_of_measure' => $external->unitOfMeasure,
                                'category' => $external->category,
                                'erp_status' => $external->status,
                                'erp_raw_data' => $external->rawData,
                                'erp_last_synced_at' => $external->updatedAt,
                            ]);

                            $part->is_active = $external->status === 'active';

                            $part->save();

                            if ($isNew) {
                                $createdCount++;
                            } else {
                                $updatedCount++;
                            }
                        });
                    } catch (\Exception $e) {
                        $failedCount++;
                        $job->errors()->create([
                            'external_id' => $external->code,
                            'error_type' => 'row_error',
                            'error_message' => $e->getMessage(),
                            'payload' => $external->rawData,
                        ]);
                    }
                }

                $cursor = $result['next_cursor'];
                $hasMore = $cursor !== null;
            }

            $beforeEnd = $job->toArray();
            $job->update([
                'status' => $failedCount > 0 ? 'partial' : 'success',
                'total_records' => $totalRecords,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'failed_count' => $failedCount,
                'completed_at' => now(),
            ]);
            $logger->log('sync_parts_completed', $job, $beforeEnd, $job->fresh()->toArray());

        } catch (\Exception $e) {
            $beforeEnd = $job->toArray();
            $job->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            $logger->log('sync_parts_failed', $job, $beforeEnd, $job->fresh()->toArray());

            throw $e;
        }

        return $job;
    }
}