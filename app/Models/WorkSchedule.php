<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSchedule extends Model
{
    protected $fillable = [
        'employee_id', 'working_days', 'start_time', 'end_time', 'lunch_start', 'lunch_end',
        'grace_period_minutes', 'effective_from', 'effective_to', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'working_days' => 'array',
            'grace_period_minutes' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function defaults(): array
    {
        return [
            'id' => null,
            'employee_id' => null,
            'working_days' => [1, 2, 3, 4, 5],
            'start_time' => '08:00:00',
            'lunch_start' => '12:00:00',
            'lunch_end' => '13:00:00',
            'end_time' => '17:00:00',
            'grace_period_minutes' => 0,
            'effective_from' => null,
            'effective_to' => null,
            'reason' => 'Default municipal office schedule',
        ];
    }

    public static function forEmployeeOnDate(Employee $employee, string $date): array
    {
        $schedule = self::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->latest('effective_from')
            ->first();

        if (! $schedule) {
            $schedule = self::query()
                ->whereNull('employee_id')
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
                ->latest('effective_from')
                ->first();
        }

        return $schedule?->attributesForCalculation() ?? self::defaults();
    }

    public static function resolveFor(Employee $employee, string $date): array
    {
        $schedule = self::forEmployeeOnDate($employee, $date);
        $holiday = Holiday::query()->whereDate('holiday_date', $date)->where('is_active', true)->first();

        if ($holiday) {
            $classification = match ($holiday->holiday_type) {
                'regular' => 'regular_holiday',
                'special_non_working' => 'special_non_working_holiday',
                default => 'unclassified_holiday',
            };
        } else {
            $weekday = Carbon::parse($date)->dayOfWeekIso;
            $classification = in_array($weekday, $schedule['working_days'], true)
                ? 'regular_working_day'
                : 'rest_day';
        }

        return [
            'schedule' => $schedule,
            'calendar_classification' => $classification,
        ];
    }

    public function attributesForCalculation(): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'working_days' => array_map('intval', $this->working_days ?? []),
            'start_time' => $this->start_time,
            'lunch_start' => $this->lunch_start,
            'lunch_end' => $this->lunch_end,
            'end_time' => $this->end_time,
            'grace_period_minutes' => $this->grace_period_minutes,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'reason' => $this->reason,
        ];
    }
}