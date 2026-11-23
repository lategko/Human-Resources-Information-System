<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeHeadAvailability extends Model
{
    protected $fillable = [
        'department_id',
        'office_head_user_id',
        'availability_status',
        'start_date',
        'end_date',
        'reason',
        'alternate_user_id',
        'updated_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function officeHead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'office_head_user_id');
    }

    public function alternate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alternate_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OfficeHeadAvailabilityHistory::class, 'availability_id')->oldest();
    }
}
