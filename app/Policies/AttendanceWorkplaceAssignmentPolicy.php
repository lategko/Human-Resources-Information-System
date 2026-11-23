<?php

namespace App\Policies;

use App\Models\User;

class AttendanceWorkplaceAssignmentPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdministrator();
    }
}
