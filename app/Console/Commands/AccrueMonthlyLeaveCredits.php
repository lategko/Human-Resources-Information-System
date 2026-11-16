<?php

namespace App\Console\Commands;

use App\Services\MonthlyLeaveAccrualService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AccrueMonthlyLeaveCredits extends Command
{
    protected $signature = 'leave:accrue-monthly {--month= : Accrual month in YYYY-MM format; defaults to the previous month}';

    protected $description = 'Accrue eligible monthly Vacation Leave and Sick Leave credits.';

    public function handle(MonthlyLeaveAccrualService $service): int
    {
        $input = $this->option('month');
        try {
            $month = $input
                ? Carbon::createFromFormat('!Y-m', $input)
                : now()->subMonthNoOverflow()->startOfMonth();
        } catch (\Throwable) {
            $this->error('The month option must use YYYY-MM format.');

            return self::INVALID;
        }
        if (! $month || ($input && $month->format('Y-m') !== $input)) {
            $this->error('The month option must use YYYY-MM format.');

            return self::INVALID;
        }
        if ($month->greaterThanOrEqualTo(now()->startOfMonth())) {
            $this->error('Monthly accrual can only be run for a completed month.');

            return self::INVALID;
        }

        $summary = $service->accrue($month);
        $this->info(sprintf(
            '%s accrual complete: %d employee(s), %d transaction(s), %.2f credits.',
            $month->format('F Y'),
            $summary['employees'],
            $summary['transactions'],
            $summary['credits']
        ));

        return self::SUCCESS;
    }
}
