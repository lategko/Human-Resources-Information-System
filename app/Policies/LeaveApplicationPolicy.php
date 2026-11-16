<?php

namespace App\Policies;

use App\Models\LeaveApplication;
use App\Models\User;

class LeaveApplicationPolicy
{
    public function view(User $user, LeaveApplication $application): bool
    {
        if ($user->employee?->id === $application->employee_id) {
            return true;
        }

        if ($user->canManageHr()) {
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
                || ($application->status === 'Pending Authorized Final Approval'
                    && $application->assigned_final_approver_id === $user->id)
                || $application->decided_by === $user->id
                || $application->alternate_decision_by === $user->id;
        }

        if ($user->canApproveMayorLeave()) {
            return $application->status === 'Pending Authorized Final Approval'
                && $application->assigned_final_approver_id === $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->employee !== null;
    }

    public function update(User $user, LeaveApplication $application): bool
    {
        return $user->employee?->id === $application->employee_id
            && in_array($application->status, ['Draft', 'Returned for Correction'], true);
    }

    public function review(User $user, LeaveApplication $application): bool
    {
        if ($user->employee?->id === $application->employee_id) {
            return false;
        }

        if ($user->isOfficeHead()) {
            return in_array($application->status, ['Under Office Head Review', 'Under Alternate Office Head Review'], true)
                && $application->office_head_reviewer_id === $user->id;
        }

        if ($user->isMayor() && $application->status === 'Under Alternate Office Head Review') {
            return $application->office_head_reviewer_id === $user->id;
        }

        if ($user->isAdministrator()
            && in_array($application->status, [
                'Awaiting Alternate HR Reviewer Assignment',
                'Awaiting Authorized Final Approver Assignment',
            ], true)) {
            return true;
        }

        if ($user->isHrmo() && $application->status === 'Under HRMO Verification') {
            if ($application->assigned_hr_reviewer_id && $application->assigned_hr_reviewer_id !== $user->id) {
                return false;
            }

            return ! $application->employee?->user?->isAdministrator()
                || $application->assigned_hr_reviewer_id === $user->id;
        }

        if ($user->isMayor() && $application->status === 'Pending Final Approval') {
            return true;
        }

        return $user->canApproveMayorLeave()
            && $application->status === 'Pending Authorized Final Approval'
            && $application->assigned_final_approver_id === $user->id;
    }

    public function cancel(User $user, LeaveApplication $application): bool
    {
        return $user->employee?->id === $application->employee_id
            && in_array($application->status, ['Draft', 'Submitted', 'Under Office Head Review', 'Returned for Correction'], true);
    }

    public function viewDocument(User $user, LeaveApplication $application, bool $sensitive): bool
    {
        if ($sensitive) {
            return $user->canManageHr()
                || $user->employee?->id === $application->employee_id
                || ($user->isMayor()
                    && $application->office_head_reviewer_id === $user->id
                    && $application->status === 'Under Alternate Office Head Review')
                || ($user->isMayor() && $application->status === 'Pending Final Approval')
                || ($user->canApproveMayorLeave()
                    && $application->status === 'Pending Authorized Final Approval'
                    && $application->assigned_final_approver_id === $user->id);
        }

        return $this->view($user, $application);
    }
}
