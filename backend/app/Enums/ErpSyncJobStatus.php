<?php

namespace App\Enums;

enum ErpSyncJobStatus: string
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case PARTIAL = 'partial';
    case FAILED = 'failed';
}
