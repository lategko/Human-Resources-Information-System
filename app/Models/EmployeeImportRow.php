<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportRow extends Model
{
    protected $fillable = [
        'employee_import_id',
        'employee_id',
        'reviewed_by',
        'source_row',
        'source_data',
        'review_data',
        'issues',
        'status',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_data' => 'array',
            'review_data' => 'array',
            'issues' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EmployeeImport::class, 'employee_import_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
