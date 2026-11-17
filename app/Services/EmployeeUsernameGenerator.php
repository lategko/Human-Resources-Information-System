<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class EmployeeUsernameGenerator
{
    public function generate(string $name, string $employeeNumber, ?int $ignoreUserId = null): string
    {
        $base = $this->slug($name) ?: 'employee';
        if (! $this->isTaken($base, $ignoreUserId)) {
            return $base;
        }

        $employeeSuffix = $this->slug($employeeNumber) ?: 'staff';
        $candidate = "{$base}.{$employeeSuffix}";
        $sequence = 2;

        while ($this->isTaken($candidate, $ignoreUserId)) {
            $candidate = "{$base}.{$employeeSuffix}.{$sequence}";
            $sequence++;
        }

        return $candidate;
    }

    private function slug(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($value)));
        $slug = preg_replace('/[^a-z0-9]+/', '.', $ascii);

        return trim((string) $slug, '.');
    }

    private function isTaken(string $username, ?int $ignoreUserId): bool
    {
        return User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists();
    }
}
