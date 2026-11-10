<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'name', 'code', 'guidance', 'required_documents', 'rules',
        'requires_credit', 'is_sensitive', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required_documents' => 'array',
            'rules' => 'array',
            'requires_credit' => 'boolean',
            'is_sensitive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }
}
