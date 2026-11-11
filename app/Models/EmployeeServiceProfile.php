<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeServiceProfile extends Model
{
    protected $fillable = [
        'employee_id',
        'position_level',
        'source_classification',
        'eligibility_rating',
        'professional_license',
        'license_expiration_date',
        'educational_attainment',
        'years_in_service',
        'expected_retirement_date',
        'age_at_source',
        'additional_service_years',
        'employee_import_id',
        'source_row',
    ];

    protected function casts(): array
    {
        return [
            'license_expiration_date' => 'date',
            'expected_retirement_date' => 'date',
        ];
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
