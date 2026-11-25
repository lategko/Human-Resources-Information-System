<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ConfiguredAccountSeeder extends Seeder
{
    private const ROLES = [
        'admin' => 'admin',
        'mayor' => 'mayor',
        'employee' => 'employee',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            User::query()
                ->whereIn('role', ['super_admin', 'hrmo'])
                ->update(['role' => 'admin']);

            foreach (config('hris.initial_accounts', []) as $key => $account) {
                if (! is_array($account)) {
                    throw new RuntimeException("The configured initial account '{$key}' must be an array.");
                }

                $this->synchronizeAccount($key, $account);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function synchronizeAccount(string $key, array $account): void
    {
        if (! isset(self::ROLES[$key])) {
            throw new RuntimeException("Unsupported initial account slot '{$key}'. Configure only the four canonical roles.");
        }

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

        if (array_key_exists('password', $account) && $account['password'] !== null && ! is_string($account['password'])) {
            throw new RuntimeException("The configured initial account '{$key}' password must be a string.");
        }

        $role = self::ROLES[$key];
        $department = null;
        $departmentId = $account['department_id'] ?? null;
        if (filled($departmentId)) {
            if (ctype_digit((string) $departmentId)) {
                $department = Department::query()->lockForUpdate()->find($departmentId);
            }

            if (! $department) {
                $this->warnDepartmentIssue(
                    $key,
                    $departmentId,
                    "the configured department ID does not exist; the employee department will remain unassigned"
                );
            }
        } elseif ($key === 'office_head') {
            $this->warnDepartmentIssue(
                $key,
                null,
                'no department ID is configured; department-specific Office Head access is unavailable'
            );
        }

        $user = User::query()->where('seed_key', $key)->lockForUpdate()->first();

        if (! $user) {
            $user = User::query()->where('email', $account['email'])->lockForUpdate()->first();

            if ($user) {
                $legacyAdminKey = $key === 'admin' && in_array($user->seed_key, ['super_admin', 'hrmo'], true);
                $expectedLegacyRoles = $key === 'admin' ? ['admin', 'super_admin', 'hrmo'] : [$role];

                if (! in_array($user->role, $expectedLegacyRoles, true)
                    || ($user->seed_key !== null && $user->seed_key !== $key && ! $legacyAdminKey)) {
                    throw new RuntimeException(
                        "The configured initial account '{$key}' email is assigned to an unrelated account; no account data was changed."
                    );
                }

                if ($legacyAdminKey) {
                    $user->seed_key = $key;
                }
            }
        }

        if (! $user && ! filled($account['password'] ?? null)) {
            throw new RuntimeException("The configured initial account '{$key}' requires a password when first created.");
        }

        $emailOwner = User::query()
            ->where('email', $account['email'])
            ->when($user, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->lockForUpdate()
            ->first();

        if ($emailOwner) {
            throw new RuntimeException(
                "The configured initial account '{$key}' email is already assigned to another user; no account data was changed."
            );
        }

        $isNewUser = ! $user;
        $user ??= new User;
        if ($isNewUser) {
            $user->name = $account['name'];
            $user->is_active = true;
        }
        $user->name = $account['name'];
        $user->email = $account['email'];
        if (filled($account['password'] ?? null)
            && ($isNewUser || ! Hash::check($account['password'], (string) $user->password))) {
            $user->password = Hash::make($account['password']);
        }
        $user->role = $role;
        if ($role !== 'admin') {
            $user->can_final_approve = false;
        }
        if (in_array($role, ['admin', 'mayor', 'office_head'], true) && ! $user->hasVerifiedEmail()) {
            $user->email_verified_at = now();
        }
        $user->seed_key = $key;
        $user->save();

        $employee = Employee::withTrashed()->where('user_id', $user->id)->lockForUpdate()->first();
        if ($employee?->trashed()) {
            throw new RuntimeException("The configured initial account '{$key}' is linked to an archived employee record.");
        }

        $employeeNo = $account['employee_no'] ?? null;
        if (! $employee && (! is_string($employeeNo) || trim($employeeNo) === '' || strlen($employeeNo) > 50)) {
            $this->warnDepartmentIssue($key, null, 'no employee number is configured; the account has no employee record yet');
            $this->clearOfficeHeadAssignment($key, $user);

            return;
        }

        if (! $employee) {
            $employee = Employee::withTrashed()->where('employee_no', $employeeNo)->lockForUpdate()->first();
            if ($employee && ($employee->user_id !== null || $employee->trashed())) {
                throw new RuntimeException(
                    "Employee number '{$employeeNo}' is already assigned or archived; no employee record was changed."
                );
            }

            if ($employee) {
                $expectedName = $this->employeeName($user->name, $key);
                if ($employee->first_name !== $expectedName['first_name']
                    || $employee->last_name !== $expectedName['last_name']) {
                    throw new RuntimeException(
                        "Employee number '{$employeeNo}' belongs to a different employee; no employee record was changed."
                    );
                }
            } else {
                $employee = new Employee;
            }
        }

        $name = $this->employeeName($user->name, $key);
        $employee->user_id = $user->id;
        if (filled($account['employee_no'] ?? null)) {
            $employeeNoConflict = Employee::withTrashed()
                ->where('employee_no', $account['employee_no'])
                ->whereKeyNot($employee->getKey())
                ->exists();
            if ($employeeNoConflict) {
                throw new RuntimeException(
                    "Employee number '{$account['employee_no']}' is already assigned to another employee record."
                );
            }
            $employee->employee_no = $account['employee_no'];
        } elseif (! $employee->exists) {
            $this->warnDepartmentIssue($key, null, 'no employee number is configured; the account has no employee record yet');

            return;
        }
        $employee->department_id = $department?->id;
        $employee->first_name = $name['first_name'];
        $employee->last_name = $name['last_name'];
        $employee->email = $user->email;
        if (! $employee->exists) {
            $employee->employment_status = 'Active';
        }
        $employee->save();

        $this->synchronizeOfficeHeadAssignment($key, $user, $department);
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    private function employeeName(string $name, string $key): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            throw new RuntimeException("The configured initial account '{$key}' requires a non-empty name.");
        }

        return [
            'first_name' => array_shift($parts),
            'last_name' => implode(' ', $parts) ?: ' ',
        ];
    }

    private function warnDepartmentIssue(string $key, mixed $departmentId, string $reason): void
    {
        $message = "Initial account '{$key}': {$reason}.";
        if ($departmentId !== null) {
            $message .= " Configured department ID: '{$departmentId}'.";
        }

        $this->command?->warn($message);
        Log::warning($message);
    }

    private function clearOfficeHeadAssignment(string $key, User $user): void
    {
        if ($key === 'office_head') {
            Department::query()
                ->where('head_user_id', $user->id)
                ->lockForUpdate()
                ->update(['head_user_id' => null]);
        }
    }

    private function synchronizeOfficeHeadAssignment(string $key, User $user, ?Department $department): void
    {
        if ($key !== 'office_head') {
            return;
        }

        if ($department && $department->head_user_id && (int) $department->head_user_id !== (int) $user->id) {
            throw new RuntimeException("The configured Office Head department is already assigned to another user.");
        }

        Department::query()
            ->where('head_user_id', $user->id)
            ->when($department, fn ($query) => $query->whereKeyNot($department->id))
            ->lockForUpdate()
            ->update(['head_user_id' => null]);

        if ($department && (int) $department->head_user_id !== (int) $user->id) {
            $department->update(['head_user_id' => $user->id]);
        }
    }
}
