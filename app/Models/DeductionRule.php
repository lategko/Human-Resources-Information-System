<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeductionRule extends Model
{
    protected $fillable = ['name', 'code', 'description', 'calculation_config', 'is_active'];

    protected function casts(): array
    {
        return ['calculation_config' => 'array', 'is_active' => 'boolean'];
    }
}
