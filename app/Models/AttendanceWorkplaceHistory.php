<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWorkplaceHistory extends Model
{
    protected $table = 'attendance_workplace_history';

    protected $fillable = [
        'attendance_workplace_id', 'changed_by', 'latitude', 'longitude',
        'radius_meters', 'required_accuracy_meters', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'required_accuracy_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
