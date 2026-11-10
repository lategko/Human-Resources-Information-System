<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveCredit extends Model
{
    protected $fillable = [
        'employee_id', 'leave_type_id', 'beginning_balance', 'earned_credits',
        'used_credits', 'adjustments', 'effective_date',
    ];

    protected function casts(): array
    {
        return [
            'beginning_balance' => 'decimal:2',
            'earned_credits' => 'decimal:2',
            'used_credits' => 'decimal:2',
            'adjustments' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->beginning_balance + (float) $this->earned_credits
            + (float) $this->adjustments - (float) $this->used_credits;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }
}
