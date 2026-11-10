<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WorkingDayCalculator
{
    public function count(string|\DateTimeInterface $start, string|\DateTimeInterface $end): int
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        if ($from->gt($to)) {
            return 0;
        }

        $holidays = DB::table('holidays')
            ->where('is_active', true)
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $configuredWeek = DB::table('system_settings')->where('key', 'workweek_days')->value('value');
        $workingDays = is_string($configuredWeek) ? json_decode($configuredWeek, true) : null;
        if (! is_array($workingDays) || $workingDays === []) {
            $workingDays = [1, 2, 3, 4, 5];
        }
        $workingDays = array_map('intval', $workingDays);

        $count = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (in_array($day->dayOfWeekIso, $workingDays, true) && ! in_array($day->toDateString(), $holidays, true)) {
                $count++;
            }
        }

        return $count;
    }
}
