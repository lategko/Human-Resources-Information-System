<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class EmployeeUsernameGenerator
{
    public function generate(string $name, string $employeeNumber, ?int $ignoreUserId = null): string
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $suffix = null;
        if ($parts && preg_match('/^(jr\.?|sr\.?|ii|iii|iv|v)$/i', (string) end($parts))) {
            $suffix = array_pop($parts);
        }
        $firstName = array_shift($parts) ?? '';
        $lastName = implode(' ', $parts);

        return $this->generateForNameParts($firstName, null, $lastName, $suffix, $employeeNumber, $ignoreUserId);
    }

    public function generateForNameParts(
        string $firstName,
        ?string $middleName,
        string $lastName,
        ?string $suffix,
        string $employeeNumber,
        ?int $ignoreUserId = null
    ): string {
        $baseParts = array_filter([
            $this->slug($firstName),
            $this->slug($middleName ?? ''),
            $this->slugCollapsed($lastName),
            $this->slug($suffix ?? ''),
        ]);
        $base = mb_substr(implode('.', $baseParts), 0, 100);
        $base = rtrim($base, '.');
        if ($base === '') {
            $base = 'employee.'.($this->slugCollapsed($employeeNumber) ?: 'staff');
        }
        if (! $this->isTaken($base, $ignoreUserId)) {
            return $base;
        }

        $sequence = 2;
        do {
            $suffix = (string) $sequence;
            $candidate = mb_substr($base, 0, 100 - mb_strlen($suffix)).$suffix;
            $sequence++;
        } while ($this->isTaken($candidate, $ignoreUserId));

        return $candidate;
    }

    private function slug(string $value): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($value)));
        $slug = preg_replace('/[^a-z0-9]+/', '.', $ascii);

        return trim((string) $slug, '.');
    }

    private function slugCollapsed(string $value): string
    {
        return str_replace('.', '', $this->slug($value));
    }

    private function isTaken(string $username, ?int $ignoreUserId): bool
    {
        return User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->when($ignoreUserId, fn ($query) => $query->whereKeyNot($ignoreUserId))
            ->exists();
    }
}
