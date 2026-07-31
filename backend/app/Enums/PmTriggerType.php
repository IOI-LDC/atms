<?php

namespace App\Enums;

enum PmTriggerType: string
{
    case DATE = 'date';
    case READING = 'reading';
    case DATE_OR_READING = 'date_or_reading';
}
