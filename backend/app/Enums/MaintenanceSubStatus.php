<?php

namespace App\Enums;

enum MaintenanceSubStatus: string
{
    case INSTALLED = 'Installed';
    case READY = 'Ready';
    case LIH = 'LIH';
    case DBR = 'DBR';
    case DISPOSED = 'Disposed';
    case SCRAPPED = 'Scrapped';
    case OTHER = 'Other';
}
