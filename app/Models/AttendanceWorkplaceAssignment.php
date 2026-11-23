<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceWorkplaceAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'department_id',
        'attendance_workplace_id',
        'assignment_type',
        'effective_from',
        'assigned_by',
        'reason',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workplace(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplace::class, 'attendance_workplace_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AttendanceWorkplaceAssignmentHistory::class, 'assignment_id');
    }
}
