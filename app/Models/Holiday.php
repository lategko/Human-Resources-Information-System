<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $fillable = ['holiday_date', 'name', 'holiday_type', 'is_active'];

    protected function casts(): array
    {
        return ['holiday_date' => 'date', 'is_active' => 'boolean'];
    }
}
