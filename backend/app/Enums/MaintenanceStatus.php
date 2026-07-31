<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    case ENROLLED = 'enrolled';
    case WITHDRAWN = 'withdrawn';
}
