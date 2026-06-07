<?php

namespace App\Actions\Erp;

use App\Contracts\Erp\ErpSource;
use App\Models\Part;
use App\Models\ErpSyncJob;
use Illuminate\Support\Facades\DB;

class SyncParts
{
    public function __construct(
        private ErpSource $source
    ) {}

    public function execute(?int $triggeredByUserId = null): ErpSyncJob
    {
        $job = ErpSyncJob::create([
            'sync_type' => 'parts',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        try {
            $cursor = null;
            $hasMore = true;

            $lastSync = Part::max('erp_last_synced_at');

            while ($hasMore) {
                $result = $this->source->getParts($lastSync, $cursor);
                
                foreach ($result['data'] as $external) {
                    $job->increment('total_records');

                    try {
                        DB::transaction(function () use ($external, $job) {
                            $part = Part::firstOrNew(['erp_part_code' => $external->code]);
                            
                            $isNew = ! $part->exists;

                            $part->fill([
                                'erp_part_id' => (string) $external->id,
                                'name' => $external->name,
                                'description' => $external->description,
                                'unit_of_measure' => $external->unitOfMeasure,
                                'category' => $external->category,
                                'erp_status' => $external->status,
                                'erp_raw_data' => $external->rawData,
                                'erp_last_synced_at' => $external->updatedAt,
                            ]);

                            if ($external->status !== 'active') {
                                $part->is_active = false;
                            }

                            $part->save();

                            if ($isNew) {
                                $job->increment('created_count');
                            } else {
                                $job->increment('updated_count');
                            }
                        });
                    } catch (\Exception $e) {
                        $job->increment('failed_count');
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

            $job->update([
                'status' => $job->failed_count > 0 ? 'partial' : 'success',
                'completed_at' => now(),
            ]);

        } catch (\Exception $e) {
            $job->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }

        return $job;
    }
}
