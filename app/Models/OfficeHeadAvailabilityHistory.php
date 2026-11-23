<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeHeadAvailabilityHistory extends Model
{
    protected $fillable = [
        'availability_id',
        'actor_id',
        'action',
        'before_state',
        'after_state',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(OfficeHeadAvailability::class, 'availability_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
