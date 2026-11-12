<?php

namespace App\Policies;

use App\Models\LeaveApplication;
use App\Models\User;

class LeaveApplicationPolicy
{
    public function view(User $user, LeaveApplication $application): bool
    {
        if ($user->canManageHr() || $user->isAdministrator()) {
            return true;
        }

        if ($user->isOfficeHead()) {
            return $application->office_head_reviewer_id === $user->id
                || $application->employee?->department?->head_user_id === $user->id;
        }

        if ($user->canFinalApprove()) {
            return $application->status === 'Pending Final Approval'
                || ($application->status === 'Under Alternate Office Head Review'
                    && $application->office_head_reviewer_id === $user->id)
                || $application->decided_by === $user->id
                || $application->alternate_decision_by === $user->id;
        }

        return $user->employee?->id === $application->employee_id;
    }

    public function create(User $user): bool
    {
        return $user->isEmployee() && $user->employee !== null;
    }

    public function update(User $user, LeaveApplication $application): bool
    {
        return $user->isEmployee()
            && $user->employee?->id === $application->employee_id
            && in_array($application->status, ['Draft', 'Returned for Correction'], true);
    }

    public function review(User $user, LeaveApplication $application): bool
    {
        if ($user->isOfficeHead()) {
            return in_array($application->status, ['Under Office Head Review', 'Under Alternate Office Head Review'], true)
                && ($application->office_head_reviewer_id
                    ? $application->office_head_reviewer_id === $user->id
                    : $application->employee?->department?->head_user_id === $user->id);
        }

        if ($user->canFinalApprove()
            && $application->status === 'Under Alternate Office Head Review'
            && $application->office_head_reviewer_id === $user->id) {
            return true;
        }

        return $user->isHrmo() && $application->status === 'Under HRMO Verification'
            || $user->canFinalApprove() && $application->status === 'Pending Final Approval';
    }

    public function cancel(User $user, LeaveApplication $application): bool
    {
        return $user->isEmployee()
            && $user->employee?->id === $application->employee_id
            && in_array($application->status, ['Draft', 'Submitted', 'Under Office Head Review', 'Returned for Correction'], true);
    }

    public function viewDocument(User $user, LeaveApplication $application, bool $sensitive): bool
    {
        if ($sensitive) {
            return $user->canManageHr()
                || $user->isAdministrator()
                || $user->employee?->id === $application->employee_id;
        }

        return $this->view($user, $application);
    }
}
