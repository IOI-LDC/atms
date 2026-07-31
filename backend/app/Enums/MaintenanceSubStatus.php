<?php

namespace App\Enums;

enum MaintenanceSubStatus: string
{
    case INSTALLED = 'installed';
    case READY = 'ready';
    case LIH = 'lih';
    case DBR = 'dbr';
    case DISPOSED = 'disposed';
    case SCRAPPED = 'scrapped';
    case OTHER = 'other';
}
