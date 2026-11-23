<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'aliases', 'head_user_id', 'is_active'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_active' => 'boolean'];
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function workplaceAssignments(): HasMany
    {
        return $this->hasMany(AttendanceWorkplaceAssignment::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function officeHeadAvailabilities(): HasMany
    {
        return $this->hasMany(OfficeHeadAvailability::class);
    }
}
