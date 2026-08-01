<?php

namespace App\Enums;

/**
 * Where a PM assignment came from, which decides who is allowed to withdraw it.
 *
 * Reconciliation only ever touches CATEGORY rows. A MANUAL row is somebody's
 * deliberate decision about one asset and survives every category change.
 */
enum PmAssignmentOrigin: string
{
    case MANUAL = 'manual';

    case CATEGORY = 'category';
}
