<?php

namespace App\Enums;

/**
 * Where an asset physically is, expressed as a utilisation bucket.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS IS THE SINGLE SOURCE OF TRUTH FOR "OUT FOR WORK vs IDLE".
 *
 * If LDC defines deployment differently, change `forLocationType()` below and
 * nothing else — every utilisation figure in the application derives from it.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * | Location type | Bucket      | Rationale                                    |
 * |---------------|-------------|----------------------------------------------|
 * | `rig`         | DEPLOYED    | On a rig — earning                           |
 * | `well_site`   | DEPLOYED    | On a well site — earning                     |
 * | `yard`        | IDLE        | On base, available, not earning              |
 * | `building`    | IDLE        | Stored indoors, not earning                  |
 * | `workshop`    | MAINTENANCE | Being worked on — not idle, not deployed     |
 *
 * `workshop` is deliberately its own bucket rather than being folded into IDLE:
 * counting maintenance time as idle time makes the maintenance function look
 * like dead time, which is the opposite of what this system exists to show.
 *
 * An unrecognised location type returns null — reported as "unclassified" rather
 * than absorbed into a bucket, so a newly added type is visible instead of
 * silently distorting the percentage. `AssetDeploymentClassificationTest`
 * asserts every type present in the database maps to a bucket.
 */
enum AssetDeployment: string
{
    case DEPLOYED = 'deployed';
    case IDLE = 'idle';
    case MAINTENANCE = 'maintenance';

    /**
     * Classify a raw `locations.type` value. Null means "not classified" —
     * either the asset has no location, or its location carries a type this
     * enum does not know about.
     */
    public static function forLocationType(?string $type): ?self
    {
        if ($type === null) {
            return null;
        }

        return match (LocationType::tryFrom($type)) {
            LocationType::RIG, LocationType::WELL_SITE => self::DEPLOYED,
            LocationType::YARD, LocationType::BUILDING => self::IDLE,
            LocationType::WORKSHOP => self::MAINTENANCE,
            null => null,
        };
    }
}
