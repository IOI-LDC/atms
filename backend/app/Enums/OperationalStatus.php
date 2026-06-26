<?php

namespace App\Enums;

enum OperationalStatus: string
{
    case ACTIVE = 'active';
    case UNDER_MAINTENANCE = 'under_maintenance';
    case DOWN = 'down';
    case INACTIVE = 'inactive';
}
