<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCreditTransaction extends Model
{
    protected $fillable = [
        'leave_credit_id', 'leave_application_id', 'created_by', 'type', 'amount',
        'previous_balance', 'balance_after', 'effective_date', 'accrual_month', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'previous_balance' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'effective_date' => 'date',
            'accrual_month' => 'date',
        ];
    }

    public function leaveCredit(): BelongsTo
    {
        return $this->belongsTo(LeaveCredit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }
}
