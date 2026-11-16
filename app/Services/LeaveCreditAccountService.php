<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveCredit;
use App\Models\LeaveType;
use Illuminate\Support\Facades\DB;

class LeaveCreditAccountService
{
    public function ensureRegularAccounts(Employee $employee): void
    {
        foreach (['VACATION', 'SICK'] as $code) {
            $type = LeaveType::query()->where('code', $code)->first();
            if (! $type) {
                continue;
            }

            DB::table('leave_credits')->insertOrIgnore([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'beginning_balance' => 0,
                'earned_credits' => 0,
                'used_credits' => 0,
                'adjustments' => 0,
                'effective_date' => today()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
