<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class ConfiguredAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('hris.initial_accounts', []) as $key => $account) {
            if (! is_array($account)) {
                throw new RuntimeException("The configured initial account '{$key}' must be an array.");
            }

            $this->synchronizeAccount($key, $account);
        }
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function synchronizeAccount(string $key, array $account): void
    {
        $credentials = array_intersect_key($account, array_flip(['name', 'email', 'password']));
        if (! collect($credentials)->contains(fn ($value) => filled($value))) {
            return;
        }

        if (! filled($account['name'] ?? null) || ! filled($account['email'] ?? null)) {
            throw new RuntimeException("The configured initial account '{$key}' requires a name and email.");
        }

        if (! filter_var($account['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("The configured initial account '{$key}' requires a valid email address.");
        }

        $role = $account['role'] ?? $key;
        if (! in_array($role, User::VALID_ROLES, true)) {
            throw new RuntimeException("The configured initial account '{$key}' has an unsupported role: {$role}.");
        }

        $department = null;
        if ($role === 'office_head') {
            if (! filled($account['department_id'] ?? null)) {
                $this->command?->warn(
                    "Skipping configured Office Head '{$key}': set HRIS_INITIAL_OFFICE_HEAD_DEPARTMENT_ID "
                    .'to an existing department ID. Create the department first; the seeder does not create departments.'
                );

                return;
            }

            $department = Department::find($account['department_id']);

            if (! $department) {
                $this->command?->warn(
                    "Skipping configured Office Head '{$key}': department ID '{$account['department_id']}' "
                    .'does not exist. Create the department first or set the value to an existing ID; '
                    .'the seeder does not create departments.'
                );

                return;
            }
        }

        if (array_key_exists('password', $account) && $account['password'] !== null && ! is_string($account['password'])) {
            throw new RuntimeException("The configured initial account '{$key}' password must be a string.");
        }

        if ($key === '') {
            throw new RuntimeException('Every configured initial account must have a stable configuration identifier.');
        }

        $isActive = $this->configuredBoolean($account['is_active'] ?? null, 'is_active', $key);
        $canFinalApprove = $this->configuredBoolean($account['can_final_approve'] ?? null, 'can_final_approve', $key);

        DB::transaction(function () use ($key, $account, $role, $department, $isActive, $canFinalApprove): void {
            $user = User::query()->where('seed_key', $key)->lockForUpdate()->first();

            if (! $user) {
                $legacyUser = User::query()->where('email', $account['email'])->lockForUpdate()->first();

                if ($legacyUser) {
                    $legacyRoles = [$key];
                    if ($key === 'super_admin') {
                        $legacyRoles[] = 'admin';
                    }

                    if (! in_array($legacyUser->role, $legacyRoles, true)
                        || $legacyUser->role === 'employee'
                        || $legacyUser->employee()->exists()) {
                        throw new RuntimeException(
                            "The configured initial account '{$key}' email is assigned to an unrelated account; "
                            .'no account data was changed.'
                        );
                    }

                    if ($legacyUser->seed_key !== null && $legacyUser->seed_key !== $key) {
                        throw new RuntimeException(
                            "The configured initial account '{$key}' email belongs to configured account '{$legacyUser->seed_key}'."
                        );
                    }

                    $user = $legacyUser;
                }
            }

            $emailOwner = User::query()
                ->where('email', $account['email'])
                ->when($user, fn ($query) => $query->whereKeyNot($user->getKey()))
                ->lockForUpdate()
                ->first();

            if ($emailOwner) {
                throw new RuntimeException(
                    "The configured initial account '{$key}' email is already assigned to another user; "
                    .'no account data was changed.'
                );
            }

            if (! $user && ! filled($account['password'] ?? null)) {
                throw new RuntimeException("The configured initial account '{$key}' requires a password when first created.");
            }

            if ($department && $department->head_user_id && (int) $department->head_user_id !== (int) $user?->id) {
                throw new RuntimeException(
                    "The configured Office Head department for '{$key}' is already assigned to another user."
                );
            }

            $previousDepartment = $user?->role === 'office_head'
                ? Department::query()->where('head_user_id', $user->id)->lockForUpdate()->first()
                : null;

            if ($previousDepartment && (! $department || $previousDepartment->isNot($department))) {
                $previousDepartment->update(['head_user_id' => null]);
            }

            $attributes = [
                'name' => $account['name'],
                'email' => $account['email'],
                'role' => $role,
            ];
            if ($isActive !== null) {
                $attributes['is_active'] = $isActive;
            } elseif (! $user) {
                $attributes['is_active'] = true;
            }

            if ($role !== 'admin') {
                $attributes['can_final_approve'] = false;
            } elseif ($canFinalApprove !== null) {
                $attributes['can_final_approve'] = $canFinalApprove;
            } elseif (! $user) {
                $attributes['can_final_approve'] = false;
            }

            if (filled($account['password'] ?? null)
                && (! $user || ! Hash::check($account['password'], $user->password))) {
                $attributes['password'] = Hash::make($account['password']);
            }

            $user ??= new User;
            $user->fill($attributes);
            $user->seed_key = $key;
            $user->save();

            if ($department && (int) $department->head_user_id !== (int) $user->id) {
                $department->update(['head_user_id' => $user->id]);
            }
        });
    }

    private function configuredBoolean(mixed $value, string $option, string $key): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            throw new RuntimeException("The configured initial account '{$key}' {$option} value must be true or false.");
        }

        return $parsed;
    }
}
