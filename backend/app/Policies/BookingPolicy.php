<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Booking $booking): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::LOGISTICS);
    }
}
