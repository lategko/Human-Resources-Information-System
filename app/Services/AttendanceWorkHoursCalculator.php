<?php

namespace App\Services;

use DateTimeImmutable;

class AttendanceWorkHoursCalculator
{
    public function calculate(
        ?string $timeIn,
        ?string $timeOut,
        ?string $lunchStart = null,
        ?string $lunchEnd = null
    ): ?float
    {
        if ($timeIn === null || $timeOut === null) {
            return null;
        }

        $start = $this->secondsSinceMidnight($timeIn);
        $end = $this->secondsSinceMidnight($timeOut);
        if ($end < $start) {
            $end += 24 * 60 * 60;
        }

        $duration = $end - $start;
        if ($lunchStart !== null && $lunchEnd !== null) {
            $breakStart = $this->secondsSinceMidnight($lunchStart);
            $breakEnd = $this->secondsSinceMidnight($lunchEnd);
            if ($breakEnd <= $breakStart) {
                $breakEnd += 24 * 60 * 60;
            }
            if ($breakStart < $start) {
                $breakStart += 24 * 60 * 60;
                $breakEnd += 24 * 60 * 60;
            }
            $duration -= max(0, min($end, $breakEnd) - max($start, $breakStart));
        }

        return round(max(0, $duration) / 3600, 2);
    }

    public function calculateMetrics(?string $timeIn, ?string $timeOut, array $schedule, string $calendarClassification): array
    {
        $hours = $this->calculate($timeIn, $timeOut, $schedule['lunch_start'] ?? null, $schedule['lunch_end'] ?? null);
        $metrics = [
            'total_working_hours' => $hours,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'unapproved_extra_minutes' => 0,
            'calculation_warning' => $timeIn === null || $timeOut === null
                ? 'A time-in or time-out is missing; review this attendance record.'
                : null,
        ];

        if ($timeIn === null || $timeOut === null) {
            return $metrics;
        }

        $start = $this->secondsSinceMidnight($schedule['start_time']);
        $end = $this->secondsSinceMidnight($schedule['end_time']);
        if ($end <= $start) {
            $end += 24 * 60 * 60;
        }
        $actualStart = $this->secondsSinceMidnight($timeIn);
        $actualEnd = $this->secondsSinceMidnight($timeOut);
        if ($actualEnd < $actualStart) {
            $actualEnd += 24 * 60 * 60;
        }

        if ($actualStart >= $end || $actualEnd <= $start) {
            $metrics['calculation_warning'] = 'Recorded times fall outside the assigned work schedule; review the schedule or attendance record.';

            return $metrics;
        }

        $metrics['unapproved_extra_minutes'] = intdiv(max(0, $actualEnd - $end), 60);

        if ($calendarClassification !== 'regular_working_day') {
            return $metrics;
        }

        $graceSeconds = max(0, (int) ($schedule['grace_period_minutes'] ?? 0)) * 60;
        $metrics['late_minutes'] = intdiv(max(0, $actualStart - $start - $graceSeconds), 60);
        $metrics['undertime_minutes'] = intdiv(max(0, $end - $actualEnd), 60);

        return $metrics;
    }

    private function secondsSinceMidnight(string $time): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', $time)
            ?: DateTimeImmutable::createFromFormat('!H:i', $time);
        if (! $parsed) {
            throw new \InvalidArgumentException('Attendance times must use a validated 24-hour time format.');
        }

        return ((int) $parsed->format('H') * 3600)
            + ((int) $parsed->format('i') * 60)
            + (int) $parsed->format('s');
    }
}
