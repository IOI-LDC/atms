<?php

namespace App\Models;

use Database\Factories\MaintenanceCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A controlled Maintenance Category shared by Assets and Parts.
 *
 * Local ATMS data, unrelated to any ERP classification. Rows arrive through the
 * controlled workbook import and can also be created or edited by an Admin via
 * the administration UI (`MaintenanceCategoryController`).
 */
class MaintenanceCategory extends Model
{
    /** @use HasFactory<MaintenanceCategoryFactory> */
    use HasFactory;

    /**
     * The category an asset lands in when nothing better is known.
     *
     * `assets.maintenance_category_id` defaults to this row, which is what lets
     * the ERP sync keep creating assets against a NOT NULL column. It is a real
     * category on purpose — unclassified assets stay visible in every filter,
     * report and dashboard count instead of vanishing into a null.
     */
    public const UNCLASSIFIED_CODE = 'UNCLASSIFIED';

    public const UNCLASSIFIED_NAME = 'Unclassified';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Derive the stable category code from a human-readable name.
     *
     * Mirrors the transformation used by the workbook import so a category
     * created in the Admin UI yields the same code as one created by import.
     */
    public static function codeFor(string $name): string
    {
        $code = preg_replace('/[^A-Z0-9]+/', '_', mb_strtoupper($name)) ?? '';

        return trim($code, '_');
    }

    public function isUnclassified(): bool
    {
        return $this->code === self::UNCLASSIFIED_CODE;
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return HasMany<Part, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }
}
