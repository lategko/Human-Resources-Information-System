<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ConfiguredAccountSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('hris.initial_accounts', []) as $role => $account) {
            $values = array_intersect_key($account, array_flip(['name', 'email', 'password']));
            $configured = array_filter($values, fn ($value) => filled($value));

            if ($configured === []) {
                continue;
            }

            if (count($configured) !== count($values)) {
                throw new RuntimeException("The initial {$role} account requires a name, email, and password.");
            }

            if (! filter_var($account['email'], FILTER_VALIDATE_EMAIL)
                || mb_strlen($account['password']) < 12
                || ! in_array($role, User::VALID_ROLES, true)) {
                throw new RuntimeException("The configured initial {$role} account has invalid account information.");
            }

            $existingUser = User::where('email', $account['email'])->first();

            if ($existingUser) {
                if ($role === 'super_admin' && ! $existingUser->isSuperAdmin()) {
                    throw new RuntimeException('The configured Super Admin email is already assigned to a non-Super Admin account.');
                }

                continue;
            }

            $department = null;
            if ($role === 'office_head') {
                if (! filled($account['department_id'] ?? null)) {
                    throw new RuntimeException('The configured Office Head account requires an existing department assignment.');
                }

                $department = Department::find($account['department_id']);

                if (! $department) {
                    throw new RuntimeException('The configured Office Head department does not exist.');
                }

                if ($department->head_user_id) {
                    throw new RuntimeException('The configured Office Head department is already assigned to another user.');
                }
            }

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'role' => $role,
                    'is_active' => true,
                    'can_final_approve' => $role === 'admin' && ($account['can_final_approve'] ?? false),
                    'password' => $account['password'],
                ],
            );

            if ($user->wasRecentlyCreated && $department) {
                $department->update(['head_user_id' => $user->id]);
            }
        }
    }
}
