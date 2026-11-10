<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCreditTransaction extends Model
{
    protected $fillable = [
        'leave_credit_id', 'leave_application_id', 'created_by', 'type', 'amount',
        'balance_after', 'effective_date', 'remarks',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'effective_date' => 'date'];
    }

    public function leaveCredit(): BelongsTo
    {
        return $this->belongsTo(LeaveCredit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
