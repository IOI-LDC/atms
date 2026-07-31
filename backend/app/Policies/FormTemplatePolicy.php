<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\FormTemplate;
use App\Models\User;

/**
 * Form templates are Admin-only for every capability (including view). Field
 * management abilities delegate to the same Admin gate. Value/sync/defer on a
 * Work Order's form reuse WorkOrderPolicy::updateExecution in the controller.
 */
class FormTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function view(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function update(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function deactivate(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function reactivate(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function addField(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function updateField(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function deleteField(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }

    public function reorderFields(User $user, FormTemplate $template): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR);
    }
}
