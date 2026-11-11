<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'department_id', 'position_id', 'employee_no', 'biometric_id',
        'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'date_of_birth',
        'civil_status', 'contact_number', 'email', 'address', 'employment_status',
        'employment_type', 'date_hired', 'salary_grade', 'salary_step', 'monthly_salary',
        'government_ids', 'emergency_contact_name', 'emergency_contact_number', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_hired' => 'date',
            'archived_at' => 'date',
            'monthly_salary' => 'decimal:2',
            'government_ids' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function leaveCredits(): HasMany
    {
        return $this->hasMany(LeaveCredit::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function employmentHistories(): HasMany
    {
        return $this->hasMany(EmployeeEmploymentHistory::class)->orderBy('sequence');
    }

    public function serviceProfile(): HasOne
    {
        return $this->hasOne(EmployeeServiceProfile::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }
}
