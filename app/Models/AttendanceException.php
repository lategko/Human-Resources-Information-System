<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceException extends Model
{
    protected $fillable = [
        'employee_id', 'attendance_record_id', 'attendance_date', 'requested_action',
        'explanation', 'supporting_document_path', 'status', 'reviewed_by',
        'reviewed_at', 'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
