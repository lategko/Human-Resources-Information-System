<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id', 'work_date', 'time_in', 'time_out', 'break_minutes',
        'total_working_hours', 'late_minutes', 'undertime_minutes', 'status',
        'source', 'correction_remarks', 'corrected_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'total_working_hours' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deductionRecord(): HasOne
    {
        return $this->hasOne(DeductionRecord::class);
    }
}
