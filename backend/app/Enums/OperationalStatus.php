<?php

namespace App\Enums;

/**
 * The machine axis: is this asset working right now?
 *
 * ⚠️ **Mid-transition (release 4a of the 2026-08-16 vocabulary change).** The
 * four cases below the divider are the agreed final vocabulary; the four above
 * it are legacy and are removed in release 4b, together with the migration that
 * rewrites the rows still carrying them. Both sets are present here on purpose:
 * 4a must deserialize existing `down` / `scraped` rows without throwing, while
 * accepting the new values, so old and new code can run against one database
 * during the rollout.
 *
 * Do not write a legacy value from new code, and do not add behaviour keyed to
 * one — everything above the divider is scheduled for deletion.
 *
 * Causes live on `assets.condition_status` (Need Assembly / Missing Parts /
 * Need Inspection), not here. `down` becomes `failure` because LDC reads "down"
 * as "waiting for parts", which is a condition, not an operational state.
 */
enum OperationalStatus: string
{
    case READY_FOR_FIELD = 'ready_for_field';
    case UNDER_MAINTENANCE = 'under_maintenance';
    case FAILURE = 'failure';
    case AT_THE_FIELD = 'at_the_field';

    // ── Legacy — removed in release 4b ────────────────────────────────────────
    case DOWN = 'down';
    case SCRAPED = 'scraped';
    case UNDER_INSPECTION = 'under_inspection';
    case LIH = 'lih';

    /**
     * The vocabulary release 4b leaves behind. Use this anywhere a list of
     * choices is offered, so a picker never shows a value on its way out.
     *
     * @return list<self>
     */
    public static function current(): array
    {
        return [self::READY_FOR_FIELD, self::UNDER_MAINTENANCE, self::FAILURE, self::AT_THE_FIELD];
    }

    /**
     * Values that exist only so 4a can read rows 4b has not rewritten yet.
     *
     * @return list<self>
     */
    public static function legacy(): array
    {
        return [self::DOWN, self::SCRAPED, self::UNDER_INSPECTION, self::LIH];
    }
}
