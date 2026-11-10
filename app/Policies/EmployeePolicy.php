<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageHr() || $user->isAdministrator() || $user->isOfficeHead();
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->canManageHr() || $user->isAdministrator()) {
            return true;
        }

        if ($user->isOfficeHead()) {
            return $employee->department?->head_user_id === $user->id;
        }

        return $user->employee?->is($employee) ?? false;
    }

    public function create(User $user): bool
    {
        return $user->canManageHr();
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->canManageHr();
    }

    public function archive(User $user, Employee $employee): bool
    {
        return $user->isAdministrator();
    }

    public function updatePersonal(User $user, Employee $employee): bool
    {
        return $user->isEmployee() && $user->employee?->is($employee);
    }
}
