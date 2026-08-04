<?php

namespace App\Enums;

enum OperationalStatus: string
{
    case READY_FOR_FIELD = 'ready_for_field';
    case UNDER_MAINTENANCE = 'under_maintenance';
    case DOWN = 'down';
    case SCRAPED = 'scraped';
    case UNDER_INSPECTION = 'under_inspection';
    case LIH = 'lih';
}
