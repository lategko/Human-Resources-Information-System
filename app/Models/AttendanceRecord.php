<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'time_in', 'time_out', 'break_minutes',
        'total_working_hours', 'late_minutes', 'undertime_minutes', 'status',
        'source', 'correction_remarks', 'corrected_by', 'calendar_classification',
        'unapproved_extra_minutes', 'schedule_snapshot', 'calculation_warning',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'total_working_hours' => 'decimal:2',
            'unapproved_extra_minutes' => 'integer',
            'schedule_snapshot' => 'array',
        ];
    }

    public function getDisplayStatusAttribute(): string
    {
        if ($this->status !== 'Present') {
            return $this->status;
        }

        return match ($this->calendar_classification) {
            'rest_day' => 'Present - Worked on Rest Day',
            'regular_holiday' => 'Present - Worked on Regular Holiday',
            'special_non_working_holiday' => 'Present - Worked on Special Non-Working Holiday',
            default => $this->status,
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

}
