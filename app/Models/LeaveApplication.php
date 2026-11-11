<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveApplication extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'status', 'office_snapshot', 'position_snapshot',
        'salary_snapshot', 'last_name_snapshot', 'first_name_snapshot', 'middle_name_snapshot',
        'filed_at', 'other_leave_type', 'leave_detail_category', 'leave_detail', 'destination',
        'additional_details', 'working_days', 'verified_working_days', 'start_date', 'end_date',
        'commutation_requested', 'applicant_acknowledgment', 'document_waivers',
        'credit_certification', 'certified_by', 'certified_at', 'certifier_position',
        'certifier_signature', 'hr_remarks', 'recommendation',
        'recommendation_remarks', 'recommended_by', 'recommended_at', 'approved_days_with_pay',
        'approved_days_without_pay', 'approval_others', 'disapproval_reason', 'decision_remarks',
        'decided_by', 'decided_at', 'office_head_reviewer_id', 'office_head_routing_reason',
        'alternate_decision_by', 'alternate_decision_action', 'alternate_decision_days_with_pay',
        'alternate_decision_days_without_pay', 'alternate_decision_others', 'alternate_decision_reason',
        'alternate_decision_remarks', 'alternate_decision_at',
    ];

    protected function casts(): array
    {
        return [
            'filed_at' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'commutation_requested' => 'boolean',
            'document_waivers' => 'array',
            'credit_certification' => 'array',
            'certified_at' => 'datetime',
            'recommended_at' => 'datetime',
            'decided_at' => 'datetime',
            'alternate_decision_at' => 'datetime',
            'salary_snapshot' => 'decimal:2',
            'approved_days_with_pay' => 'decimal:2',
            'approved_days_without_pay' => 'decimal:2',
            'alternate_decision_days_with_pay' => 'decimal:2',
            'alternate_decision_days_without_pay' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveApplicationDocument::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(LeaveApprovalHistory::class)->latest();
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function officeHeadReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'office_head_reviewer_id');
    }

    public function alternateDecisionMaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alternate_decision_by');
    }
}
