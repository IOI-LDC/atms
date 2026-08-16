<?php

namespace App\Enums;

use App\Support\Assets\AssetFieldStatus;

/**
 * The machine axis: is this asset working right now?
 *
 * Four values, agreed with LDC on 2026-08-16 and narrowed to this set in
 * release 4b:
 *
 * | Value              | Meaning                                              |
 * |--------------------|------------------------------------------------------|
 * | `ready_for_field`  | Serviceable and on base, available to send out        |
 * | `under_maintenance`| Work is happening on it right now                     |
 * | `failure`          | Broken — not serviceable until repaired               |
 * | `at_the_field`     | Out on a rig or well site                             |
 *
 * Two properties are worth knowing before changing anything here:
 *
 *  - **`at_the_field` is derived, never chosen.** It is written when an asset
 *    moves to a location `AssetDeployment` classifies as DEPLOYED, and it is
 *    deliberately absent from every manual picker — offering it would let
 *    someone claim an asset is on a rig while its location says otherwise.
 *    {@see AssetFieldStatus}
 *  - **Causes are not statuses.** Why an asset is not serviceable lives on
 *    `assets.condition_status` (Need Assembly / Missing Parts / Need
 *    Inspection), an Admin-editable vocabulary. `down` became `failure`
 *    precisely because LDC read "down" as "waiting for parts" — a condition
 *    wearing an operational state's clothes.
 *
 * The legacy values (`down`, `scraped`, `under_inspection`, `lih`) were removed
 * here in 4b, after `2026_08_16_000003_migrate_operational_status_values`
 * rewrote every row carrying one. That migration must run before this enum is
 * deployed: the cast would otherwise throw on any surviving row.
 */
enum OperationalStatus: string
{
    case READY_FOR_FIELD = 'ready_for_field';
    case UNDER_MAINTENANCE = 'under_maintenance';
    case FAILURE = 'failure';
    case AT_THE_FIELD = 'at_the_field';

    /**
     * The values a person may pick.
     *
     * `at_the_field` is excluded on purpose — location changes own it. Every
     * manual status control (asset create, asset update, the work-order
     * "Update status…" dialog) validates against this list, not `cases()`.
     *
     * @return list<self>
     */
    public static function manuallySelectable(): array
    {
        return [self::READY_FOR_FIELD, self::UNDER_MAINTENANCE, self::FAILURE];
    }

    /**
     * @return list<string>
     */
    public static function manuallySelectableValues(): array
    {
        return array_map(fn (self $case) => $case->value, self::manuallySelectable());
    }

    /**
     * Human-readable label. Kept here rather than in a resource because three
     * separate surfaces render it (asset lists, reports, CSV headers) and they
     * must not drift apart.
     */
    public function label(): string
    {
        return match ($this) {
            self::READY_FOR_FIELD => 'Ready for Field',
            self::UNDER_MAINTENANCE => 'Under Maintenance',
            self::FAILURE => 'Failure',
            self::AT_THE_FIELD => 'At the Field',
        };
    }
}
