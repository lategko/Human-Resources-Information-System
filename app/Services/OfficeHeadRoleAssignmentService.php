<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeHeadRoleAssignmentService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function synchronizeVerifiedPosition(Position $position, User $actor, bool $recordRoleChange = true): void
    {
        DB::transaction(function () use ($position, $actor, $recordRoleChange): void {
            $position = Position::query()->lockForUpdate()->findOrFail($position->id);
            if (! $position->is_office_head_designation) {
                return;
            }
            if (! $position->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => 'An HR-approved Office Head position must belong to a department.',
                ]);
            }

            $employees = Employee::query()
                ->where('position_id', $position->id)
                ->whereNull('archived_at')
                ->where('employment_status', 'Active')
                ->lockForUpdate()
                ->get();
            if ($employees->count() > 1) {
                throw ValidationException::withMessages([
                    'position_id' => 'Multiple active employees hold this designated Office Head position. Resolve the assignment before synchronizing roles.',
                ]);
            }
            $employee = $employees->first();
            if (! $employee) {
                return;
            }
            if ((int) $employee->department_id !== (int) $position->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => 'The employee and designated Office Head position must belong to the same department.',
                ]);
            }
            if (! $employee->user_id) {
                return;
            }

            $department = Department::query()->lockForUpdate()->findOrFail($position->department_id);
            $user = User::query()->lockForUpdate()->findOrFail($employee->user_id);
            if ($user->isAdministrator() || $user->isMayor()) {
                throw ValidationException::withMessages([
                    'position_id' => 'Protected Admin and Mayor accounts cannot be reassigned as Office Heads.',
                ]);
            }
            if ($department->head_user_id && (int) $department->head_user_id !== (int) $user->id) {
                throw ValidationException::withMessages([
                    'department_id' => 'This department already has another assigned Office Head.',
                ]);
            }
            if (Department::query()
                ->where('head_user_id', $user->id)
                ->whereKeyNot($department->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'department_id' => 'This employee is already assigned as the head of another department.',
                ]);
            }

            $previousRole = $user->role;
            $previousHeadUserId = $department->head_user_id;
            if ($previousRole !== 'office_head') {
                $user->role = 'office_head';
                $user->save();
            }
            if ((int) $previousHeadUserId !== (int) $user->id) {
                $department->head_user_id = $user->id;
                $department->save();
            }

            if ($recordRoleChange && $previousRole !== 'office_head') {
                $this->auditLogger->record('user.role_changed', $user, [
                    'previous_role' => $previousRole,
                    'new_role' => 'office_head',
                    'employee_id' => $employee->id,
                    'position_id' => $position->id,
                    'department_id' => $department->id,
                    'administrator_id' => $actor->id,
                    'reason' => 'Synchronized from an HR-approved Office Head position designation.',
                ]);
            }
            if ((int) $previousHeadUserId !== (int) $user->id) {
                $this->auditLogger->record('department.office_head_assigned', $department, [
                    'previous_head_user_id' => $previousHeadUserId,
                    'new_head_user_id' => $user->id,
                    'employee_id' => $employee->id,
                    'position_id' => $position->id,
                    'administrator_id' => $actor->id,
                ]);
            }
        });
    }

    public function revokePosition(Position $position, User $actor): void
    {
        DB::transaction(function () use ($position, $actor): void {
            $position = Position::query()->lockForUpdate()->findOrFail($position->id);
            if ($position->is_office_head_designation) {
                return;
            }

            $employees = Employee::query()
                ->where('position_id', $position->id)
                ->whereNull('archived_at')
                ->whereNotNull('user_id')
                ->lockForUpdate()
                ->get();
            foreach ($employees as $employee) {
                $user = User::query()->lockForUpdate()->findOrFail($employee->user_id);
                $department = Department::query()
                    ->where('head_user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if ($department) {
                    $department->head_user_id = null;
                    $department->save();
                    $this->auditLogger->record('department.office_head_assignment_revoked', $department, [
                        'previous_head_user_id' => $user->id,
                        'employee_id' => $employee->id,
                        'position_id' => $position->id,
                        'administrator_id' => $actor->id,
                    ]);
                }

                if ($user->role !== 'office_head' || $user->isAdministrator() || $user->isMayor()) {
                    continue;
                }

                $user->role = 'employee';
                $user->save();
                $this->auditLogger->record('user.role_changed', $user, [
                    'previous_role' => 'office_head',
                    'new_role' => 'employee',
                    'employee_id' => $employee->id,
                    'position_id' => $position->id,
                    'department_id' => $employee->department_id,
                    'administrator_id' => $actor->id,
                    'reason' => 'The Office Head position designation was revoked.',
                ]);
            }
        });
    }

    public function revokeEmployeeFromPosition(
        int $employeeId,
        int $previousPositionId,
        ?int $previousDepartmentId,
        User $actor
    ): void {
        DB::transaction(function () use ($employeeId, $previousPositionId, $previousDepartmentId, $actor): void {
            $position = Position::query()->lockForUpdate()->findOrFail($previousPositionId);
            if (! $position->is_office_head_designation) {
                return;
            }

            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);
            if (! $employee->user_id) {
                return;
            }
            $user = User::query()->lockForUpdate()->findOrFail($employee->user_id);
            if ($user->role !== 'office_head' || $user->isAdministrator() || $user->isMayor()) {
                return;
            }

            $department = $previousDepartmentId
                ? Department::query()->lockForUpdate()->find($previousDepartmentId)
                : null;
            if ($department && (int) $department->head_user_id === (int) $user->id) {
                $department->head_user_id = null;
                $department->save();
                $this->auditLogger->record('department.office_head_assignment_revoked', $department, [
                    'previous_head_user_id' => $user->id,
                    'employee_id' => $employee->id,
                    'position_id' => $position->id,
                    'administrator_id' => $actor->id,
                ]);
            }

            $user->role = 'employee';
            $user->save();
            $this->auditLogger->record('user.role_changed', $user, [
                'previous_role' => 'office_head',
                'new_role' => 'employee',
                'employee_id' => $employee->id,
                'previous_position_id' => $position->id,
                'previous_department_id' => $previousDepartmentId,
                'administrator_id' => $actor->id,
                'reason' => 'The employee left the HR-approved Office Head position.',
            ]);
        });
    }
}
