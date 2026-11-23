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
        'time_in_latitude', 'time_in_longitude', 'time_in_accuracy_meters', 'time_in_distance_meters',
        'time_in_workplace_id', 'time_in_verified_at', 'time_out_latitude', 'time_out_longitude',
        'time_out_accuracy_meters', 'time_out_distance_meters', 'time_out_workplace_id',
        'time_out_verified_at', 'gps_verification_status',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'total_working_hours' => 'decimal:2',
            'unapproved_extra_minutes' => 'integer',
            'schedule_snapshot' => 'array',
            'time_in_verified_at' => 'datetime',
            'time_out_verified_at' => 'datetime',
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

    public function timeInWorkplace(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplace::class, 'time_in_workplace_id');
    }

    public function timeOutWorkplace(): BelongsTo
    {
        return $this->belongsTo(AttendanceWorkplace::class, 'time_out_workplace_id');
    }

}
