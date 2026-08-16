<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MasterDataItem extends Model
{
    /**
     * Vocabularies this table holds. Admin-editable content, fixed group keys —
     * the keys are referenced from code and route parameters, so they are the
     * one part of a vocabulary that is not an Admin's to rename.
     */
    public const MAINTENANCE_PRIORITIES = 'maintenance_priorities';

    public const ASSET_CONDITIONS = 'asset_conditions';

    /** @var list<string> Groups the Admin master-data CRUD will serve. */
    public const MANAGED_GROUPS = [
        self::MAINTENANCE_PRIORITIES,
        self::ASSET_CONDITIONS,
    ];

    protected $fillable = [
        'group_key',
        'value',
        'label',
        'sort_order',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The value an automatic reset returns a record to — resolved from the
     * table so the choice stays an Admin action rather than a deploy.
     *
     * A partial unique index guarantees at most one per group, so `first()`
     * here is deterministic rather than query-order luck. Returns null when a
     * group has no default; callers treat that as "leave the value alone",
     * never as "write null".
     */
    public static function defaultFor(string $groupKey): ?self
    {
        return static::query()
            ->where('group_key', $groupKey)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /** @return Builder<self> */
    public static function activeIn(string $groupKey)
    {
        return static::query()
            ->where('group_key', $groupKey)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }
}
