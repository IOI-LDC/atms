<?php

namespace App\Enums;

enum RoleCode: string
{
    case ADMINISTRATOR = 'administrator';
    case MAINTENANCE_MANAGER = 'maintenance_manager';
    case TECHNICIAN = 'technician';
    case LOGISTICS = 'logistics';
    case REQUESTER = 'requester';
    case SERVICE = 'service';
}
