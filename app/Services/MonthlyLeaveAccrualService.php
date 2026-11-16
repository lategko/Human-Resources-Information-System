<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyLeaveAccrualService
{
    public function accrue(Carbon $month): array
    {
        $month = $month->copy()->startOfMonth();
        $period = $month->toDateString();
        $accrualDate = $month->copy()->endOfMonth()->toDateString();
        $summary = ['employees' => 0, 'transactions' => 0, 'credits' => 0.0];
        $types = LeaveType::query()
            ->whereIn('code', ['VACATION', 'SICK'])
            ->where('is_active', true)
            ->get()
            ->keyBy('code');

        if ($types->count() !== 2) {
            throw new \RuntimeException('Vacation Leave and Sick Leave policies must both be configured before monthly accrual.');
        }

        Employee::query()
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($types, $month, $period, $accrualDate, &$summary): void {
                foreach ($employees as $employee) {
                    DB::transaction(function () use ($employee, $types, $month, $period, $accrualDate, &$summary): void {
                        $lockedEmployee = Employee::query()->lockForUpdate()->find($employee->id);
                        if (! $lockedEmployee || $lockedEmployee->archived_at) {
                            return;
                        }

                        $employeeAccrued = false;
                        foreach (['VACATION', 'SICK'] as $code) {
                            $leaveType = $types->get($code);
                            $rules = $leaveType->rules ?? [];
                            $eligibility = $rules['accrual_eligibility'] ?? [];
                            $eligibleStatuses = $eligibility['employment_statuses'] ?? ['Active'];
                            $eligibleTypes = $eligibility['employment_types'] ?? [];
                            $effectiveFrom = $eligibility['effective_from'] ?? $rules['effective_from'] ?? null;
                            $effectiveThrough = $eligibility['effective_through'] ?? $rules['effective_through'] ?? null;
                            if (! in_array($lockedEmployee->employment_status, $eligibleStatuses, true)
                                || ($eligibleTypes !== [] && ! in_array($lockedEmployee->employment_type, $eligibleTypes, true))
                                || (($eligibility['require_date_hired'] ?? true) && ! $lockedEmployee->date_hired)
                                || ($effectiveFrom && $month->lt(Carbon::parse($effectiveFrom)->startOfMonth()))
                                || ($effectiveThrough && $month->copy()->endOfMonth()->gt(Carbon::parse($effectiveThrough)->endOfDay()))
                                || ($lockedEmployee->date_hired && $lockedEmployee->date_hired->gt($month->copy()->endOfMonth()))
                                || (($eligibility['accrue_after_full_month'] ?? true)
                                    && $lockedEmployee->date_hired
                                    && $lockedEmployee->date_hired->gt($month))) {
                                continue;
                            }

                            $amount = (float) ($rules['monthly_accrual'] ?? 1.25);
                            if ($amount <= 0 || $amount > 31) {
                                if ($amount < 0 || $amount > 31) {
                                    throw ValidationException::withMessages([
                                        'leave_policy' => "The {$leaveType->name} monthly accrual must be between 0 and 31 days.",
                                    ]);
                                }
                                continue;
                            }

                            $credit = LeaveCredit::query()->firstOrCreate(
                                ['employee_id' => $lockedEmployee->id, 'leave_type_id' => $leaveType->id],
                                ['effective_date' => $accrualDate]
                            );
                            $credit = LeaveCredit::query()->lockForUpdate()->findOrFail($credit->id);

                            $previousBalance = $credit->balance;
                            $inserted = DB::table('leave_credit_transactions')->insertOrIgnore([
                                'leave_credit_id' => $credit->id,
                                'created_by' => null,
                                'type' => 'monthly_accrual',
                                'amount' => $amount,
                                'previous_balance' => $previousBalance,
                                'balance_after' => $previousBalance + $amount,
                                'effective_date' => $accrualDate,
                                'accrual_month' => $period,
                                'remarks' => sprintf('%s monthly leave accrual', $month->format('F Y')),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            if ($inserted === 0) {
                                continue;
                            }
                            $credit->earned_credits = (float) $credit->earned_credits + $amount;
                            $credit->save();
                            $summary['transactions']++;
                            $summary['credits'] += $amount;
                            $employeeAccrued = true;
                        }

                        if ($employeeAccrued) {
                            $summary['employees']++;
                        }
                    });
                }
            });

        return $summary;
    }
}
