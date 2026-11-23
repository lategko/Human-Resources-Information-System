<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAssignmentHistory;
use App\Models\Position;

class EmployeeAssignmentHistoryRecorder
{
    public function snapshot(Employee $employee): array
    {
        return [
            'department_id' => $employee->department_id,
            'department_name' => Department::find($employee->department_id)?->name,
            'position_id' => $employee->position_id,
            'position_title' => Position::find($employee->position_id)?->title,
        ];
    }

    public function recordInitial(Employee $employee, ?int $changedBy): EmployeeAssignmentHistory
    {
        return $this->store(
            $employee,
            [],
            'initial',
            $changedBy,
            $employee->date_hired?->toDateString() ?? today()->toDateString(),
            null
        );
    }

    public function recordChange(
        Employee $employee,
        array $previous,
        ?int $changedBy,
        string $effectiveDate,
        ?string $reason = null
    ): ?EmployeeAssignmentHistory {
        $current = $this->snapshot($employee);
        $departmentChanged = (int) ($previous['department_id'] ?? 0) !== (int) ($current['department_id'] ?? 0);
        $positionChanged = (int) ($previous['position_id'] ?? 0) !== (int) ($current['position_id'] ?? 0);

        if (! $departmentChanged && ! $positionChanged) {
            return null;
        }

        return $this->store($employee, $previous, 'updated', $changedBy, $effectiveDate, $reason, $current);
    }

    private function store(
        Employee $employee,
        array $previous,
        string $changeType,
        ?int $changedBy,
        string $effectiveDate,
        ?string $reason,
        ?array $current = null
    ): EmployeeAssignmentHistory {
        $current ??= $this->snapshot($employee);

        return EmployeeAssignmentHistory::create([
            'employee_id' => $employee->id,
            'change_type' => $changeType,
            'previous_department_id' => $previous['department_id'] ?? null,
            'previous_department_name' => $previous['department_name'] ?? null,
            'previous_position_id' => $previous['position_id'] ?? null,
            'previous_position_title' => $previous['position_title'] ?? null,
            'department_id' => $current['department_id'],
            'department_name' => $current['department_name'],
            'position_id' => $current['position_id'],
            'position_title' => $current['position_title'],
            'effective_date' => $effectiveDate,
            'changed_by' => $changedBy,
            'reason' => $reason,
        ]);
    }
}
