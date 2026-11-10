<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApprovalHistory extends Model
{
    protected $fillable = [
        'leave_application_id', 'user_id', 'role', 'action', 'from_status', 'to_status', 'remarks',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
