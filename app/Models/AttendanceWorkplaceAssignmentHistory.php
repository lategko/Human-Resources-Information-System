<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWorkplaceAssignmentHistory extends Model
{
    protected $table = 'attendance_workplace_assignment_history';

    protected $fillable = [
        'assignment_id',
        'employee_id',
        'department_id',
        'previous_workplace_id',
        'new_workplace_id',
        'assignment_type',
        'effective_date',
        'assigned_by',
        'reason',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplaceAssignment::class, 'assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function previousWorkplace(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplace::class, 'previous_workplace_id');
    }

    public function newWorkplace(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplace::class, 'new_workplace_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
