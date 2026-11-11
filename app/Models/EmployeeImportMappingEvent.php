<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportMappingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['employee_import_id', 'changed_by', 'mapping', 'created_at'];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'created_at' => 'datetime'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EmployeeImport::class, 'employee_import_id');
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
