<?php

namespace App\Services;

use DateTimeImmutable;

class AttendanceWorkHoursCalculator
{
    public function calculate(?string $timeIn, ?string $timeOut): ?float
    {
        if ($timeIn === null || $timeOut === null) {
            return null;
        }

        $start = $this->secondsSinceMidnight($timeIn);
        $end = $this->secondsSinceMidnight($timeOut);
        if ($end < $start) {
            $end += 24 * 60 * 60;
        }

        return round(($end - $start) / 3600, 2);
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
