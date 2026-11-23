<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssignmentHistory extends Model
{
    protected $fillable = [
        'employee_id',
        'change_type',
        'previous_department_id',
        'previous_department_name',
        'previous_position_id',
        'previous_position_title',
        'department_id',
        'department_name',
        'position_id',
        'position_title',
        'effective_date',
        'changed_by',
        'reason',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function previousDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'previous_department_id');
    }

    public function previousPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'previous_position_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
