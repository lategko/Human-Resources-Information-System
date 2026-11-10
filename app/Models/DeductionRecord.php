<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeductionRecord extends Model
{
    protected $fillable = [
        'employee_id', 'attendance_record_id', 'deduction_rule_id', 'effective_date',
        'reason', 'estimated_amount', 'calculation_breakdown', 'review_status',
        'reviewed_by', 'reviewed_at', 'approved_amount', 'remarks',
        'approved_by', 'approved_at', 'approval_remarks',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'estimated_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'calculation_breakdown' => 'array',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
