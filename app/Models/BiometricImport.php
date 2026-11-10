<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricImport extends Model
{
    protected $fillable = ['uploaded_by', 'original_name', 'status', 'row_count', 'imported_count', 'error_count'];

    public function records(): HasMany
    {
        return $this->hasMany(BiometricImportRecord::class);
    }
}
