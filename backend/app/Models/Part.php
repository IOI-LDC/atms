<?php

namespace App\Models;

use App\Support\SizeCast;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Part extends Model
{
    protected $fillable = [
        'erp_part_id',
        'erp_part_code',
        'part_number',
        'name',
        'description',
        'unit_of_measure',
        'size_inches',
        'maintenance_category_id',
        'available_quantity',
        'erp_status',
        'erp_raw_data',
        'erp_last_synced_at',
        'is_active',
    ];

    protected $hidden = [
        'erp_raw_data',
    ];

    protected $casts = [
        'erp_raw_data' => 'array',
        'erp_last_synced_at' => 'datetime',
        'available_quantity' => 'decimal:3',
        'size_inches' => SizeCast::class,
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<MaintenanceCategory, $this>
     */
    public function maintenanceCategory(): BelongsTo
    {
        return $this->belongsTo(MaintenanceCategory::class);
    }

    /**
     * Restrict to parts compatible with an asset.
     *
     *     (part category is blank OR matches the asset category)
     * AND (part size is blank OR matches the asset size)
     *
     * A blank part value is a wildcard across that dimension. When the *asset*
     * is missing a value the equality arm is NULL rather than true, so only a
     * part blank on the same dimension matches — which is the required
     * behaviour, and the reason this must not be written as
     * `IS NOT DISTINCT FROM`.
     *
     * @param  Builder<Part>  $query
     */
    #[Scope]
    protected function compatibleWith(Builder $query, Asset $asset): void
    {
        $query
            ->where(fn (Builder $q) => $q
                ->whereNull('maintenance_category_id')
                ->orWhere('maintenance_category_id', $asset->maintenance_category_id))
            ->where(fn (Builder $q) => $q
                ->whereNull('size_inches')
                ->orWhere('size_inches', $asset->size_inches?->canonical()));
    }

    /**
     * The same rule as {@see compatibleWith}, evaluated for one part.
     *
     * Used by RecordWorkOrderPart so a request that bypasses the filtered
     * picker is still rejected server-side.
     */
    public function isCompatibleWith(Asset $asset): bool
    {
        $categoryMatches = $this->maintenance_category_id === null
            || $this->maintenance_category_id === $asset->maintenance_category_id;

        $sizeMatches = $this->size_inches === null
            || $this->size_inches->equals($asset->size_inches);

        return $categoryMatches && $sizeMatches;
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
