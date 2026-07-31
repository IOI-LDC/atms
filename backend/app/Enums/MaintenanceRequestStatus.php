<?php

namespace App\Enums;

enum MaintenanceRequestStatus: string
{
    case PENDING_REVIEW = 'pending_review';
    case REJECTED = 'rejected';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';
}
