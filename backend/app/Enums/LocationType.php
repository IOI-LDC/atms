<?php

namespace App\Enums;

/**
 * The `locations.type` vocabulary.
 *
 * The column is a plain string and is deliberately NOT cast to this enum on the
 * Location model: LDC can add a location type at any time, and a cast would make
 * every query touching that row throw. Read it with `LocationType::tryFrom()`,
 * which yields null for an unrecognised type so the caller can surface it rather
 * than silently mis-bucket it.
 *
 * @see AssetDeployment::forLocationType() for how these map to deployment state.
 */
enum LocationType: string
{
    case RIG = 'rig';
    case WELL_SITE = 'well_site';
    case YARD = 'yard';
    case WORKSHOP = 'workshop';
    case BUILDING = 'building';
}
