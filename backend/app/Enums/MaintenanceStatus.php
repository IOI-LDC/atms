<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case ACTIVE = 'Active';
    case INACTIVE = 'Inactive';
}
