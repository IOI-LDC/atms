<?php

namespace App\Actions\Parts;

use App\Models\Part;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdatePart
{
    /**
     * @param  array<string, mixed>  $fieldUpdates
     */
    public function execute(Part $part, array $fieldUpdates): Part
    {
        if (empty($fieldUpdates)) {
            return $part;
        }

        return DB::transaction(function () use ($part, $fieldUpdates) {
            $before = $part->toArray();
            $part->update($fieldUpdates);
            $after = $part->fresh()->toArray();

            app(AuditLogger::class)->log('part.updated', $part, $before, $after);

            return $part->fresh();
        });
    }
}
