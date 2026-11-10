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
            return $application->employee?->department?->head_user_id === $user->id;
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
            return $application->employee?->department?->head_user_id === $user->id
                && $application->status === 'Under Office Head Review';
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
