<?php

namespace App\Services;

use App\Models\AttendanceWorkplace;
use App\Models\AttendanceWorkplaceAssignment;
use App\Models\AttendanceWorkplaceAssignmentHistory;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceWorkplaceAssignmentService
{
    public function effectiveAssignments(iterable $employees, string $date): array
    {
        $employees = collect($employees);
        if ($employees->isEmpty()) {
            return [];
        }

        $employeeIds = $employees->pluck('id');
        $departmentIds = $employees->pluck('department_id')->filter()->unique();
        $legacyWorkplaces = AttendanceWorkplace::query()
            ->whereIn('id', $employees->pluck('attendance_workplace_id')->filter()->unique())
            ->get()
            ->keyBy('id');
        $individualRows = AttendanceWorkplaceAssignment::query()
            ->with('workplace')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');
        $departmentRows = AttendanceWorkplaceAssignment::query()
            ->with('workplace')
            ->whereIn('department_id', $departmentIds)
            ->whereNull('employee_id')
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('department_id')
            ->keyBy('department_id');

        return $employees->mapWithKeys(function (Employee $employee) use ($individualRows, $departmentRows, $legacyWorkplaces) {
            $individual = $individualRows->get($employee->id);
            if ($individual) {
                if ($individual->workplace?->is_active) {
                    return [$employee->id => $this->assignmentResult($individual)];
                }
            } else {
                $legacyWorkplace = $legacyWorkplaces->get($employee->attendance_workplace_id);
                if ($legacyWorkplace?->is_active) {
                    return [$employee->id => [
                        'workplace' => $legacyWorkplace,
                        'assignment_type' => 'individual',
                        'effective_from' => null,
                    ]];
                }
            }

            $department = $departmentRows->get($employee->department_id);
            if ($department?->workplace?->is_active) {
                return [$employee->id => $this->assignmentResult($department)];
            }

            return [$employee->id => [
                'workplace' => null,
                'assignment_type' => null,
                'effective_from' => null,
            ]];
        })->all();
    }

    public function resolve(Employee $employee, string $date): ?AttendanceWorkplace
    {
        return $this->effectiveAssignments([$employee], $date)[$employee->id]['workplace'] ?? null;
    }

    public function assignIndividual(
        int $employeeId,
        ?int $workplaceId,
        string $effectiveDate,
        int $assignedBy,
        string $reason,
        AuditLogger $auditLogger,
    ): AttendanceWorkplaceAssignment {
        return DB::transaction(function () use ($employeeId, $workplaceId, $effectiveDate, $assignedBy, $reason, $auditLogger) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeId);
            if ($employee->employment_status !== 'Active') {
                throw ValidationException::withMessages([
                    'employee_id' => 'Only active employees can be assigned an attendance workplace.',
                ]);
            }
            $workplace = $workplaceId === null ? null : $this->lockActiveWorkplace($workplaceId);
            $this->ensureNoSameDayEmployeeAssignment($employee->id, $effectiveDate);
            $previous = $this->effectiveAssignments([$employee], $effectiveDate)[$employee->id]['workplace'] ?? null;
            $assignment = AttendanceWorkplaceAssignment::create([
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'attendance_workplace_id' => $workplace?->id,
                'assignment_type' => 'individual',
                'effective_from' => $effectiveDate,
                'assigned_by' => $assignedBy,
                'reason' => $reason,
            ]);

            if ($effectiveDate <= today()->toDateString()) {
                $employee->update(['attendance_workplace_id' => $workplace?->id]);
            }

            $this->writeHistory(
                $assignment,
                $employee,
                $employee->department_id,
                $previous?->id,
                $workplace?->id,
                'individual',
                $effectiveDate,
                $assignedBy,
                $reason,
            );
            $auditLogger->record('attendance.workplace_assignment.individual', $assignment, [
                'employee_id' => $employee->id,
                'previous_workplace_id' => $previous?->id,
                'new_workplace_id' => $workplace?->id,
                'effective_date' => $effectiveDate,
            ]);

            return $assignment;
        });
    }

    public function assignEmployees(
        array $employeeIds,
        int $workplaceId,
        string $effectiveDate,
        int $assignedBy,
        string $reason,
        AuditLogger $auditLogger,
    ): int {
        return DB::transaction(function () use ($employeeIds, $workplaceId, $effectiveDate, $assignedBy, $reason, $auditLogger) {
            $employees = Employee::query()
                ->whereIn('id', $employeeIds)
                ->whereNull('archived_at')
                ->where('employment_status', 'Active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($employees->count() !== count(array_unique($employeeIds))) {
                throw ValidationException::withMessages([
                    'employee_ids' => 'One or more selected employees are unavailable for assignment.',
                ]);
            }
            $workplace = $this->lockActiveWorkplace($workplaceId);

            $this->ensureNoSameDayEmployeeAssignment($employees->modelKeys(), $effectiveDate);
            $previous = $this->effectiveAssignments($employees, $effectiveDate);
            foreach ($employees as $employee) {
                $assignment = AttendanceWorkplaceAssignment::create([
                    'employee_id' => $employee->id,
                    'department_id' => $employee->department_id,
                    'attendance_workplace_id' => $workplace->id,
                    'assignment_type' => 'bulk',
                    'effective_from' => $effectiveDate,
                    'assigned_by' => $assignedBy,
                    'reason' => $reason,
                ]);

                if ($effectiveDate <= today()->toDateString()) {
                    $employee->update(['attendance_workplace_id' => $workplace->id]);
                }

                $this->writeHistory(
                    $assignment,
                    $employee,
                    $employee->department_id,
                    $previous[$employee->id]['workplace']?->id,
                    $workplace->id,
                    'bulk',
                    $effectiveDate,
                    $assignedBy,
                    $reason,
                );
            }

            $auditLogger->record('attendance.workplace_assignment.bulk', $workplace, [
                'employee_ids' => $employees->modelKeys(),
                'count' => $employees->count(),
                'effective_date' => $effectiveDate,
            ]);

            return $employees->count();
        });
    }

    public function assignDepartment(
        int $departmentId,
        int $workplaceId,
        string $effectiveDate,
        int $assignedBy,
        string $reason,
        bool $replaceIndividualAssignments,
        AuditLogger $auditLogger,
    ): array {
        return DB::transaction(function () use (
            $departmentId,
            $workplaceId,
            $effectiveDate,
            $assignedBy,
            $reason,
            $replaceIndividualAssignments,
            $auditLogger
        ) {
            $department = Department::query()->lockForUpdate()->findOrFail($departmentId);
            if (! $department->is_active) {
                throw ValidationException::withMessages([
                    'department_id' => 'Select an active department.',
                ]);
            }
            if (AttendanceWorkplaceAssignment::query()
                ->whereNull('employee_id')
                ->where('department_id', $department->id)
                ->whereDate('effective_from', $effectiveDate)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'A department workplace assignment already exists for that effective date.',
                ]);
            }

            $employees = Employee::query()
                ->where('department_id', $department->id)
                ->whereNull('archived_at')
                ->where('employment_status', 'Active')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $workplace = $this->lockActiveWorkplace($workplaceId);
            $previous = $this->effectiveAssignments($employees, $effectiveDate);
            $previousDepartmentWorkplaceId = AttendanceWorkplaceAssignment::query()
                ->with('workplace')
                ->whereNull('employee_id')
                ->where('department_id', $department->id)
                ->whereDate('effective_from', '<=', $effectiveDate)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first()?->workplace?->id;
            $assignment = AttendanceWorkplaceAssignment::create([
                'department_id' => $department->id,
                'attendance_workplace_id' => $workplace->id,
                'assignment_type' => 'department',
                'effective_from' => $effectiveDate,
                'assigned_by' => $assignedBy,
                'reason' => $reason,
            ]);

            $replacedCount = 0;
            $replacedEmployeeIds = [];
            if ($replaceIndividualAssignments) {
                foreach ($employees as $employee) {
                    $prior = $previous[$employee->id];
                    if (! in_array($prior['assignment_type'], ['individual', 'bulk'], true)) {
                        continue;
                    }

                    AttendanceWorkplaceAssignment::create([
                        'employee_id' => $employee->id,
                        'department_id' => $department->id,
                        'attendance_workplace_id' => null,
                        'assignment_type' => 'department',
                        'effective_from' => $effectiveDate,
                        'assigned_by' => $assignedBy,
                        'reason' => $reason,
                    ]);
                    $replacedCount++;
                    $replacedEmployeeIds[] = $employee->id;
                }
            }

            foreach ($employees as $employee) {
                $replaced = in_array($employee->id, $replacedEmployeeIds, true);
                $this->writeHistory(
                    $assignment,
                    $employee,
                    $department->id,
                    $replaced
                        ? $previous[$employee->id]['workplace']?->id
                        : $previousDepartmentWorkplaceId,
                    $workplace->id,
                    'department',
                    $effectiveDate,
                    $assignedBy,
                    $reason,
                );
            }

            $auditLogger->record('attendance.workplace_assignment.department', $assignment, [
                'department_id' => $department->id,
                'employee_count' => $employees->count(),
                'replaced_individual_count' => $replacedCount,
                'effective_date' => $effectiveDate,
            ]);

            return ['employees' => $employees->count(), 'replaced' => $replacedCount];
        });
    }

    private function assignmentResult(AttendanceWorkplaceAssignment $assignment): array
    {
        return [
            'workplace' => $assignment->workplace,
            'assignment_type' => $assignment->assignment_type,
            'effective_from' => $assignment->effective_from,
        ];
    }

    private function lockActiveWorkplace(int $workplaceId): AttendanceWorkplace
    {
        $workplace = AttendanceWorkplace::query()->lockForUpdate()->find($workplaceId);
        if (! $workplace?->is_active) {
            throw ValidationException::withMessages([
                'attendance_workplace_id' => 'Select an active authorized workplace.',
            ]);
        }

        return $workplace;
    }

    private function ensureNoSameDayEmployeeAssignment(int|array $employeeIds, string $effectiveDate): void
    {
        $ids = is_array($employeeIds) ? $employeeIds : [$employeeIds];
        if (AttendanceWorkplaceAssignment::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('assignment_type', ['individual', 'bulk'])
            ->whereDate('effective_from', $effectiveDate)
            ->lockForUpdate()
            ->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'An employee workplace assignment already exists for that effective date.',
            ]);
        }
    }

    private function writeHistory(
        AttendanceWorkplaceAssignment $assignment,
        Employee $employee,
        ?int $departmentId,
        ?int $previousWorkplaceId,
        ?int $newWorkplaceId,
        string $type,
        string $effectiveDate,
        int $assignedBy,
        string $reason,
    ): void {
        AttendanceWorkplaceAssignmentHistory::create([
            'assignment_id' => $assignment->id,
            'employee_id' => $employee->id,
            'department_id' => $departmentId,
            'previous_workplace_id' => $previousWorkplaceId,
            'new_workplace_id' => $newWorkplaceId,
            'assignment_type' => $type,
            'effective_date' => $effectiveDate,
            'assigned_by' => $assignedBy,
            'reason' => $reason,
        ]);
    }
}
