<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricImportRecord extends Model
{
    protected $fillable = ['biometric_import_id', 'employee_id', 'row_number', 'raw_data', 'status', 'error_message'];

    protected function casts(): array
    {
        return ['raw_data' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BiometricImport::class, 'biometric_import_id');
    }
}
