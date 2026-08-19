<?php

namespace App\Policies;

use App\Enums\MaintenanceRequestStatus;
use App\Enums\RoleCode;
use App\Enums\WorkOrderStatus;
use App\Models\Asset;
use App\Models\Attachment;
use App\Models\MaintenanceRequest;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;

class AttachmentPolicy
{
    public function uploadToAsset(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    public function uploadToPart(User $user): bool
    {
        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::LOGISTICS);
    }

    /**
     * Attachments may be added to a Maintenance Request at any workflow stage —
     * pending_review, converted, rejected or cancelled. Workflow fields lock on
     * conversion but the evidence trail stays open.
     *
     * The creator qualifies whatever role they hold. Every role can raise an MR,
     * so gating on RoleCode::REQUESTER locked Technicians out of their own
     * requests.
     */
    public function uploadToMaintenanceRequest(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        return $maintenanceRequest->created_by === $user->id;
    }

    /**
     * Uploads stay open through COMPLETED and close only when the work order
     * does.
     *
     * That ordering is the point of RQ2: closing requires an attachment, so the
     * window between "the technician says the work is done" and "the manager
     * signs it off" is exactly when the paperwork arrives. Locking uploads at
     * completion would leave the person who did the job unable to supply the
     * evidence the close demands.
     *
     * Closed and cancelled are terminal — the user manual has always said a
     * closed work order's attachments are locked, and until 2026-08-16 nothing
     * enforced it.
     */
    public function uploadToWorkOrder(User $user, WorkOrder $workOrder): bool
    {
        if (in_array($workOrder->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
            return false;
        }

        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) && $workOrder->assigned_to_user_id === $user->id) {
            return true;
        }

        return false;
    }

    public function viewForAsset(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function viewForPart(User $user): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        return $user->hasRole(RoleCode::ADMINISTRATOR)
            || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)
            || $user->hasRole(RoleCode::TECHNICIAN)
            || $user->hasRole(RoleCode::REQUESTER);
    }

    public function viewForMaintenanceRequest(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole(RoleCode::SERVICE)) {
            return true;
        }

        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        if ($user->hasRole(RoleCode::TECHNICIAN) || $user->hasRole(RoleCode::LOGISTICS)) {
            return true;
        }

        if ($user->hasRole(RoleCode::REQUESTER)) {
            return true;
        }

        return false;
    }

    public function viewForWorkOrder(User $user): bool
    {
        return true;
    }

    public function download(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        if ($parent === null) {
            return false;
        }

        if ($parent instanceof Asset) {
            return $this->viewForAsset($user);
        }

        if ($parent instanceof Part) {
            return $this->viewForPart($user);
        }

        if ($parent instanceof MaintenanceRequest) {
            return $this->viewForMaintenanceRequest($user, $parent);
        }

        if ($parent instanceof WorkOrder) {
            return $this->viewForWorkOrder($user);
        }

        return false;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        $parent = $attachment->attachable;

        // A closed work order cannot be closed without an attachment
        // (CloseWorkOrder) and cannot receive one afterwards
        // (uploadToWorkOrder). Deleting one defeated both: the record that
        // justified the closure could be removed the minute after, leaving a
        // closed work order with no evidence and no way to supply any. Nobody
        // may do that — including an administrator, because there is no
        // legitimate reason to and no way to undo it.
        //
        // Cancelled is included for the same reason uploads are blocked there:
        // a terminal work order is a finished record, not a working document.
        if ($parent instanceof WorkOrder
            && in_array($parent->status, [WorkOrderStatus::CLOSED, WorkOrderStatus::CANCELLED], true)) {
            return false;
        }

        // Admins and managers can delete any other attachment at any stage.
        if ($user->hasRole(RoleCode::ADMINISTRATOR) || $user->hasRole(RoleCode::MAINTENANCE_MANAGER)) {
            return true;
        }

        // Anyone else may delete only their own attachment, and only while the
        // parent maintenance request is still pending review (not yet
        // converted/approved). Attachments on assets, parts or work orders are
        // admin/manager-only.
        if ($attachment->uploaded_by_user_id !== $user->id) {
            return false;
        }

        return $parent instanceof MaintenanceRequest
            && $parent->status === MaintenanceRequestStatus::PENDING_REVIEW;
    }
}
