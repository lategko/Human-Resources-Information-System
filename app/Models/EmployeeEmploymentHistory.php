<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeEmploymentHistory extends Model
{
    protected $fillable = [
        'employee_id',
        'position_title',
        'appointment_date',
        'sequence',
        'employee_import_id',
        'source_row',
    ];

    protected function casts(): array
    {
        return ['appointment_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(EmployeeImport::class, 'employee_import_id');
    }
}
