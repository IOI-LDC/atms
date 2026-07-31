<?php

namespace App\Models;

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
    /** @use HasFactory<\Database\Factories\MaintenanceCategoryFactory> */
    use HasFactory;

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
