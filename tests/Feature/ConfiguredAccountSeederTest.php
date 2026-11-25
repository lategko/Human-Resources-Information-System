<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\ConfiguredAccountSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ConfiguredAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_accounts_are_created_once_linked_and_can_log_in(): void
    {
        $accounts = [];
        foreach (['admin', 'mayor', 'employee'] as $role) {
            $accounts[$role] = $this->account("{$role}@example.test", $role);
        }
        Config::set('hris.initial_accounts', $accounts);

        $this->seed(ConfiguredAccountSeeder::class);
        $createdAt = User::where('seed_key', 'admin')->firstOrFail()->updated_at;
        $this->travel(2)->seconds();
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('employees', 3);
        $this->assertSame($createdAt->toDateTimeString(), User::where('seed_key', 'admin')->firstOrFail()->updated_at->toDateTimeString());
        foreach ([
            'admin' => 'Administration overview',
            'mayor' => 'Municipal executive overview',
            'employee' => 'My workspace',
        ] as $role => $dashboardHeading) {
            $user = User::where('seed_key', $role)->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertNotNull($user->employee);
            $this->assertSame($accounts[$role]['employee_no'], $user->employee->employee_no);
            $this->assertTrue(Hash::check('InitialPassword123!', $user->password));
            $this->assertDatabaseCount('users', 3);

            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'InitialPassword123!',
            ])->assertRedirect(in_array($role, ['admin', 'mayor'], true) ? route('mfa.verify') : route('dashboard'));
            if (in_array($role, ['admin', 'mayor'], true)) {
                $recoveryCode = strtoupper('HRIS-SEEDED-'.$role);
                $user->update(['mfa_recovery_codes' => [Hash::make($recoveryCode)]]);
                $this->post(route('mfa.verify.store'), [
                    'code' => $recoveryCode,
                ])->assertRedirect(route('dashboard'));
            }
            $this->get(route('dashboard'))
                ->assertOk()
                ->assertSee($dashboardHeading)
                ->assertDontSee('InitialPassword123!');
            $this->get(route('leave.create'))->assertOk();
            $this->get(route('leave-credits.index', ['mine' => 1]))->assertOk();
            if ($role === 'admin') {
                $this->get(route('users.index'))->assertOk();
                $this->get(route('settings.system'))->assertOk();
            } else {
                $this->get(route('users.index'))->assertForbidden();
                $this->get(route('settings.system'))->assertForbidden();
            }
            $this->post(route('logout'));
        }
    }

    public function test_database_seeder_runs_repeatedly_without_duplicate_users_or_employee_records(): void
    {
        $accounts = [];
        foreach (['admin', 'mayor', 'employee'] as $role) {
            $accounts[$role] = $this->account("{$role}@example.test", $role);
        }
        Config::set('hris.initial_accounts', $accounts);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('employees', 3);
        $this->assertSame(
            ['admin', 'employee', 'mayor'],
            User::query()->orderBy('role')->pluck('role')->all(),
        );
        $this->assertSame(3, User::whereNotNull('seed_key')->count());
        $this->assertSame(3, Employee::whereNotNull('user_id')->count());
    }

    public function test_reseeding_preserves_existing_user_details_credentials_and_employee_id(): void
    {
        $department = Department::create(['name' => 'Existing Admin Office']);
        $user = User::factory()->create([
            'name' => 'Existing Admin Name',
            'email' => 'old-admin@example.test',
            'password' => 'ExistingPassword123!',
            'role' => 'admin',
        ]);
        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'EXISTING-ADMIN-001',
            'first_name' => 'Existing',
            'last_name' => 'Admin',
        ]);
        $originalHash = $user->password;
        $account = $this->account('old-admin@example.test', 'admin');
        $account['name'] = 'Existing Admin Name';
        $account['password'] = 'ExistingPassword123!';
        Config::set('hris.initial_accounts', ['admin' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);
        $this->seed(ConfiguredAccountSeeder::class);

        $user->refresh();
        $this->assertSame('Existing Admin Name', $user->name);
        $this->assertSame('old-admin@example.test', $user->email);
        $this->assertSame($originalHash, $user->password);
        $this->assertSame('admin', $user->seed_key);
        $this->assertSame($employee->id, $user->employee->id);
        $this->assertSame(1, User::count());
        $this->post(route('login.store'), [
            'email' => 'old-admin@example.test',
            'password' => 'ExistingPassword123!',
        ])->assertRedirect(route('mfa.verify'));
        $user->update(['mfa_recovery_codes' => [Hash::make('HRIS-EXISTING-ADMIN')]]);
        $this->post(route('mfa.verify.store'), [
            'code' => 'HRIS-EXISTING-ADMIN',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_a_legacy_super_admin_account_is_reconciled_as_admin_without_promoting_employees(): void
    {
        $legacy = User::factory()->create([
            'name' => 'Legacy Admin',
            'email' => 'legacy-admin@example.test',
            'password' => 'LegacyAdminPassword123!',
            'role' => 'super_admin',
        ]);
        $legacyHrmo = User::factory()->create([
            'name' => 'Legacy HRMO',
            'email' => 'legacy-hrmo@example.test',
            'password' => 'LegacyHrmoPassword123!',
            'role' => 'hrmo',
        ]);
        $legacyHrmoHash = $legacyHrmo->password;
        $department = Department::create(['name' => 'Legacy Admin Office']);
        $account = $this->account('legacy-admin@example.test', 'admin');
        $account['employee_no'] = 'LEGACY-ADMIN-001';
        $account['department_id'] = $department->id;
        $account['password'] = 'LegacyAdminPassword123!';
        Config::set('hris.initial_accounts', [
            'admin' => $account,
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertSame($legacy->id, User::where('seed_key', 'admin')->firstOrFail()->id);
        $this->assertSame('admin', $legacy->fresh()->role);
        $this->assertNotNull($legacy->fresh()->employee);
        $this->assertTrue(Hash::check('LegacyAdminPassword123!', $legacy->fresh()->password));
        $this->assertSame('admin', $legacyHrmo->fresh()->role);
        $this->assertSame('legacy-hrmo@example.test', $legacyHrmo->fresh()->email);
        $this->assertSame($legacyHrmoHash, $legacyHrmo->fresh()->password);
        $this->assertSame(2, User::count());
        foreach ([
            ['legacy-admin@example.test', 'LegacyAdminPassword123!'],
            ['legacy-hrmo@example.test', 'LegacyHrmoPassword123!'],
        ] as [$email, $password]) {
            $user = User::where('email', $email)->firstOrFail();
            $user->update(['mfa_recovery_codes' => [Hash::make('HRIS-LEGACY-ADMIN')]]);
            $this->post(route('login.store'), compact('email', 'password'))
                ->assertRedirect(route('mfa.verify'));
            $this->post(route('mfa.verify.store'), [
                'code' => 'HRIS-LEGACY-ADMIN',
            ])->assertRedirect(route('dashboard'));
            $this->get(route('dashboard'))->assertOk()->assertSee('Administration overview');
            $this->post(route('logout'));
        }
    }

    public function test_unverified_office_head_cannot_be_bootstrapped_from_configuration(): void
    {
        $account = $this->account('office-head@example.test', 'office_head');
        Config::set('hris.initial_accounts', ['office_head' => $account]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('Office Head accounts must be verified through employee records, not bootstrapped from configuration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Unsupported initial account slot 'office_head'", $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_invalid_department_ids_do_not_stop_seeding_and_leave_assignments_unresolved(): void
    {
        Config::set('hris.initial_accounts', [
            'admin' => [
                ...$this->account('admin@example.test', 'admin'),
                'department_id' => 999,
            ],
            'employee' => [
                ...$this->account('employee@example.test', 'employee'),
                'department_id' => 999,
            ],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseHas('employees', [
            'employee_no' => User::where('seed_key', 'admin')->firstOrFail()->employee->employee_no,
            'department_id' => null,
        ]);
        $employeeUser = User::where('seed_key', 'employee')->firstOrFail();
        $this->actingAs($employeeUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Department not assigned');
        $this->get(route('employees.show', $employeeUser->employee))
            ->assertOk()
            ->assertSee('Department not assigned');
        $this->get(route('leave.create'))
            ->assertOk()
            ->assertSee('Department not assigned');
    }

    public function test_unconfigured_accounts_and_unrelated_employee_records_are_preserved(): void
    {
        $employeeUser = User::factory()->create([
            'email' => 'employee@example.test',
            'role' => 'employee',
        ]);
        $department = Department::create(['name' => 'Employee Office']);
        Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-001',
            'first_name' => 'Existing',
            'last_name' => 'Employee',
        ]);
        $unconfiguredAdmin = User::factory()->create([
            'email' => 'unconfigured@example.test',
            'role' => 'admin',
        ]);
        Config::set('hris.initial_accounts', [
            'admin' => ['name' => null, 'email' => null, 'password' => null],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame('employee', $employeeUser->fresh()->role);
        $this->assertSame('admin', $unconfiguredAdmin->fresh()->role);
        $this->assertNull($unconfiguredAdmin->fresh()->seed_key);
    }

    public function test_email_conflict_with_an_unrelated_account_fails_without_modifying_either_account(): void
    {
        $unrelated = User::factory()->create([
            'name' => 'Unrelated Mayor',
            'email' => 'claimed@example.test',
            'role' => 'mayor',
        ]);
        Config::set('hris.initial_accounts', [
            'admin' => $this->account('claimed@example.test', 'admin'),
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('A duplicate email must produce a clear conflict.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unrelated account', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Unrelated Mayor', $unrelated->fresh()->name);
        $this->assertSame('mayor', $unrelated->fresh()->role);
        $this->assertNull($unrelated->fresh()->seed_key);
    }

    public function test_email_conflict_with_an_employee_account_is_not_claimed(): void
    {
        $employee = User::factory()->create([
            'email' => 'employee@example.test',
            'role' => 'employee',
        ]);
        Config::set('hris.initial_accounts', [
            'admin' => $this->account('employee@example.test', 'admin'),
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('An employee account must never be claimed as a configured account.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unrelated account', $exception->getMessage());
        }

        $this->assertSame('employee', $employee->fresh()->role);
        $this->assertNull($employee->fresh()->seed_key);
    }

    public function test_invalid_or_incomplete_account_configuration_is_rejected(): void
    {
        Config::set('hris.initial_accounts', [
            'admin' => ['name' => 'Incomplete', 'email' => null, 'password' => null],
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('An incomplete configured account must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires a name and email', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_configured_account_is_claimed_without_overwriting_its_credentials_or_details(): void
    {
        $legacy = User::factory()->create([
            'name' => 'Old Admin Name',
            'email' => 'old-admin@example.test',
            'password' => 'OldPassword123!',
            'role' => 'admin',
        ]);
        $originalHash = $legacy->password;
        $account = $this->account('old-admin@example.test', 'admin');
        $account['password'] = 'OldPassword123!';
        Config::set('hris.initial_accounts', ['admin' => $account]);

        $this->seed(ConfiguredAccountSeeder::class);
        $this->assertSame($legacy->id, User::where('seed_key', 'admin')->firstOrFail()->id);
        $this->assertSame('Configured admin', $legacy->fresh()->name);
        $this->assertSame($originalHash, $legacy->fresh()->password);

        $account['email'] = 'new-admin@example.test';
        Config::set('hris.initial_accounts', ['admin' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertSame('new-admin@example.test', $legacy->fresh()->email);
        $this->assertSame($originalHash, $legacy->fresh()->password);
        $this->assertSame($legacy->id, User::where('seed_key', 'admin')->firstOrFail()->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(string $email, string $role): array
    {
        $department = Department::create(['name' => ucfirst($role).' '.uniqid()]);

        return [
            'name' => "Configured {$role}",
            'email' => $email,
            'password' => 'InitialPassword123!',
            'role' => $role,
            'is_active' => true,
            'employee_no' => strtoupper($role).'-'.uniqid(),
            'department_id' => $department->id,
        ];
    }
}
