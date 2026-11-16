<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\ConfiguredAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class ConfiguredAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_accounts_are_created_once_with_stable_keys_and_hashed_passwords(): void
    {
        $department = Department::create(['name' => 'Configured Office']);
        $officeHead = $this->account('office-head@example.test', 'office_head');
        $officeHead['department_id'] = $department->id;
        Config::set('hris.initial_accounts', [
            'super_admin' => $this->account('super-admin@example.test', 'super_admin'),
            'admin' => $this->account('admin@example.test', 'admin'),
            'hrmo' => $this->account('hrmo@example.test', 'hrmo'),
            'mayor' => $this->account('mayor@example.test', 'mayor'),
            'office_head' => $officeHead,
        ]);

        $this->seed(ConfiguredAccountSeeder::class);
        $createdAt = User::where('seed_key', 'admin')->firstOrFail()->updated_at;
        $this->travel(2)->seconds();
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 5);
        $this->assertSame($createdAt->toDateTimeString(), User::where('seed_key', 'admin')->firstOrFail()->updated_at->toDateTimeString());
        $this->assertSame(
            User::where('seed_key', 'office_head')->value('id'),
            $department->fresh()->head_user_id,
        );
        foreach (['super_admin', 'admin', 'hrmo', 'mayor', 'office_head'] as $role) {
            $user = User::where('seed_key', $role)->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check('InitialPassword123!', $user->password));
            $this->assertDatabaseCount('users', 5);

            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'InitialPassword123!',
            ])->assertRedirect(route('dashboard'));
            $dashboard = $this->get(route('dashboard'))->assertOk();
            $dashboard->assertViewIs($role === 'super_admin' ? 'super-admin.dashboard' : 'dashboard');
            $this->post(route('logout'));
        }
    }

    public function test_name_email_password_and_role_changes_update_the_same_account_and_authenticate(): void
    {
        $account = $this->account('old-admin@example.test', 'admin');
        Config::set('hris.initial_accounts', ['admin' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);

        $user = User::where('seed_key', 'admin')->firstOrFail();
        $unchangedHash = $user->password;
        $this->seed(ConfiguredAccountSeeder::class);
        $this->assertSame($unchangedHash, $user->fresh()->password);

        $account['name'] = 'Changed Admin Name';
        $account['email'] = 'new-mayor@example.test';
        $account['password'] = 'ChangedPassword123!';
        $account['role'] = 'mayor';
        Config::set('hris.initial_accounts', ['admin' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);

        $user->refresh();
        $this->assertSame('Changed Admin Name', $user->name);
        $this->assertSame('new-mayor@example.test', $user->email);
        $this->assertSame('mayor', $user->role);
        $this->assertSame('admin', $user->seed_key);
        $this->assertTrue(Hash::check('ChangedPassword123!', $user->password));
        $this->assertSame(1, User::count());

        $this->post(route('login.store'), [
            'email' => 'new-mayor@example.test',
            'password' => 'ChangedPassword123!',
        ])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertViewIs('dashboard');
        $this->get(route('users.index'))->assertForbidden();

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => 'old-admin@example.test',
            'password' => 'InitialPassword123!',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_super_admin_legacy_admin_account_can_be_reconciled_without_promoting_employees(): void
    {
        $legacy = User::factory()->create([
            'name' => 'Legacy Super Admin',
            'email' => 'legacy-admin@example.test',
            'role' => 'admin',
        ]);
        Config::set('hris.initial_accounts', [
            'super_admin' => $this->account('legacy-admin@example.test', 'super_admin'),
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertSame($legacy->id, User::where('seed_key', 'super_admin')->firstOrFail()->id);
        $this->assertSame('super_admin', $legacy->fresh()->role);
    }

    public function test_changing_office_head_department_releases_old_assignment_and_claims_new_one(): void
    {
        $oldDepartment = Department::create(['name' => 'Old Office']);
        $newDepartment = Department::create(['name' => 'New Office']);
        $account = $this->account('office-head@example.test', 'office_head');
        $account['department_id'] = $oldDepartment->id;
        Config::set('hris.initial_accounts', ['office_head' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);

        $user = User::where('seed_key', 'office_head')->firstOrFail();
        $account['department_id'] = $newDepartment->id;
        Config::set('hris.initial_accounts', ['office_head' => $account]);
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertNull($oldDepartment->fresh()->head_user_id);
        $this->assertSame($user->id, $newDepartment->fresh()->head_user_id);
    }

    public function test_missing_office_head_department_is_skipped_without_blocking_other_configured_accounts(): void
    {
        Config::set('hris.initial_accounts', [
            'admin' => $this->account('admin@example.test', 'admin'),
            'office_head' => [
                ...$this->account('office-head@example.test', 'office_head'),
                'department_id' => 999,
            ],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'seed_key' => 'admin']);
        $this->assertDatabaseMissing('users', ['email' => 'office-head@example.test']);
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
            'hrmo' => ['name' => null, 'email' => null, 'password' => null],
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
            'name' => 'Unrelated HRMO',
            'email' => 'claimed@example.test',
            'role' => 'hrmo',
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
        $this->assertSame('Unrelated HRMO', $unrelated->fresh()->name);
        $this->assertSame('hrmo', $unrelated->fresh()->role);
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

    public function test_legacy_configured_account_is_claimed_before_future_email_changes(): void
    {
        $legacy = User::factory()->create([
            'name' => 'Old Admin Name',
            'email' => 'old-admin@example.test',
            'password' => 'OldPassword123!',
            'role' => 'admin',
        ]);
        Config::set('hris.initial_accounts', [
            'admin' => $this->account('old-admin@example.test', 'admin'),
        ]);

        $this->seed(ConfiguredAccountSeeder::class);
        $this->assertSame($legacy->id, User::where('seed_key', 'admin')->firstOrFail()->id);
        $this->assertSame('Configured admin', $legacy->fresh()->name);

        Config::set('hris.initial_accounts', [
            'admin' => $this->account('new-admin@example.test', 'admin'),
        ]);
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertSame('new-admin@example.test', $legacy->fresh()->email);
        $this->assertSame($legacy->id, User::where('seed_key', 'admin')->firstOrFail()->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function account(string $email, string $role): array
    {
        return [
            'name' => "Configured {$role}",
            'email' => $email,
            'password' => 'InitialPassword123!',
            'role' => $role,
            'is_active' => true,
        ];
    }
}
