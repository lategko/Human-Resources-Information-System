<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceWorkplace extends Model
{
    protected $fillable = [
        'name', 'address', 'latitude', 'longitude', 'radius_meters',
        'required_accuracy_meters', 'is_active', 'created_by',
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

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'attendance_workplace_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(AttendanceWorkplaceHistory::class)->latest();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AttendanceWorkplaceAssignment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
