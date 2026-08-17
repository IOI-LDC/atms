<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Part;
use App\Models\User;

class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Part $part): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        if ($part->is_active) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER);
    }

    public function update(User $user): bool
    {
        return $this->manage($user);
    }

    /**
     * Bulk stock correction by CSV (RQ3) — **Administrator only**, deliberately
     * narrower than `manage()`.
     *
     * One upload rewrites `available_quantity` across the whole catalogue, and
     * the user manual promises it as "an interim, Administrator-only process".
     * A Maintenance Manager can still correct a single part through the ordinary
     * update path, where the blast radius is one row.
     */
    public function importQuantities(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
