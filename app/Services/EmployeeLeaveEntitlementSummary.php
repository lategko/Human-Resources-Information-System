<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeeLeaveEntitlementSummary
{
    public function forYear(Employee $employee, ?int $year = null): Collection
    {
        $year ??= today()->year;
        $start = Carbon::create($year, 1, 1)->toDateString();
        $end = Carbon::create($year, 12, 31)->toDateString();
        $configuredCredits = $employee->leaveCredits()->get()->keyBy('leave_type_id');

        return LeaveType::query()
            ->where('is_active', true)
            ->whereIn('id', $configuredCredits->keys())
            ->get()
            ->filter(fn (LeaveType $type) => ($type->rules['entitlement_period'] ?? null) === 'annual'
                && ! ($type->rules['consumes_regular_credit'] ?? false)
                && ($type->rules['entitlement_days'] ?? $type->rules['annual_entitlement_days'] ?? null) !== null)
            ->map(function (LeaveType $type) use ($employee, $configuredCredits, $year, $start, $end): array {
                $rules = $type->rules ?? [];
                $applications = LeaveApplication::query()
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->whereNotIn('status', ['Draft', 'Cancelled', 'Disapproved'])
                    ->whereBetween('start_date', [$start, $end])
                    ->get(['status', 'working_days', 'verified_working_days', 'start_date', 'end_date']);
                $used = $applications->sum(function (LeaveApplication $application) use ($rules): float {
                    if (($rules['entitlement_unit'] ?? 'working_days') === 'calendar_days') {
                        return (float) ($application->start_date?->diffInDays($application->end_date) + 1);
                    }

                    return (float) ($application->verified_working_days ?? $application->working_days ?? 0);
                });
                $policyEntitlement = (float) ($rules['entitlement_days'] ?? $rules['annual_entitlement_days']);
                $entitlement = min($policyEntitlement, $configuredCredits->get($type->id)->balance);
                $exhaustedUses = isset($rules['maximum_uses_per_year'])
                    && $applications->count() >= (int) $rules['maximum_uses_per_year'];

                return [
                    'type' => $type,
                    'entitlement' => $entitlement,
                    'used' => $used,
                    'available' => $exhaustedUses ? 0.0 : max(0.0, $entitlement - $used),
                    'year' => $year,
                    'application_count' => $applications->count(),
                ];
            })
            ->values();
    }
}
