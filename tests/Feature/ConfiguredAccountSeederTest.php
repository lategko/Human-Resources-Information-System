<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\ConfiguredAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConfiguredAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_initial_accounts_are_created_once_and_never_overwritten(): void
    {
        Config::set('hris.initial_accounts', [
            'admin' => [
                'name' => 'Authorized Setup Account',
                'email' => 'setup-admin@example.test',
                'password' => 'InitialPassword123!',
                'can_final_approve' => true,
            ],
            'hrmo' => ['name' => null, 'email' => null, 'password' => null],
            'office_head' => ['name' => null, 'email' => null, 'password' => null, 'department_id' => null],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);
        $user = User::where('email', 'setup-admin@example.test')->firstOrFail();
        $user->update([
            'name' => 'Updated by Administrator',
            'password' => 'ChangedPassword123!',
            'is_active' => false,
            'can_final_approve' => false,
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('The seeder must report an existing inactive account without changing it.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("role 'admin'", $exception->getMessage());
            $this->assertStringContainsString("status 'inactive'", $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Updated by Administrator', $user->fresh()->name);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertFalse($user->fresh()->can_final_approve);
        $this->assertTrue(Hash::check('ChangedPassword123!', $user->fresh()->password));
    }

    public function test_configured_accounts_are_hashed_and_can_authenticate_for_all_four_roles(): void
    {
        $department = Department::create(['name' => 'Configured Office']);
        $password = 'InitialPassword123!';
        $accounts = [
            'super_admin' => ['name' => 'Authorized Super Admin', 'email' => 'super-admin@example.test', 'password' => $password],
            'admin' => ['name' => 'Authorized Admin', 'email' => 'admin@example.test', 'password' => $password],
            'hrmo' => ['name' => 'Authorized HRMO', 'email' => 'hrmo@example.test', 'password' => $password],
            'office_head' => [
                'name' => 'Authorized Office Head',
                'email' => 'office-head@example.test',
                'password' => $password,
                'department_id' => $department->id,
            ],
        ];
        Config::set('hris.initial_accounts', $accounts);

        $this->seed(ConfiguredAccountSeeder::class);
        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 4);
        $this->assertSame(
            User::where('role', 'office_head')->value('id'),
            $department->fresh()->head_user_id,
        );

        foreach ($accounts as $role => $account) {
            $user = User::where('email', $account['email'])->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check($password, $user->password));

            $this->post(route('login.store'), [
                'email' => $account['email'],
                'password' => $password,
            ])->assertRedirect(route('dashboard'));

            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_short_configured_passwords_are_rejected_without_creating_an_account(): void
    {
        Config::set('hris.initial_accounts', [
            'admin' => [
                'name' => 'Authorized Admin',
                'email' => 'short-password-admin@example.test',
                'password' => 'TooShort123',
            ],
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('Passwords shorter than 12 characters must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('at least 12 characters', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'short-password-admin@example.test']);
    }

    public function test_existing_account_is_not_changed_or_blocked_by_an_unused_short_bootstrap_password(): void
    {
        $existing = User::factory()->create([
            'name' => 'Existing HRMO',
            'email' => 'existing-hrmo@example.test',
            'password' => 'ExistingPassword123!',
            'role' => 'hrmo',
            'is_active' => true,
        ]);
        Config::set('hris.initial_accounts', [
            'hrmo' => [
                'name' => 'Configured HRMO',
                'email' => 'existing-hrmo@example.test',
                'password' => 'Short123',
            ],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Existing HRMO', $existing->fresh()->name);
        $this->assertSame('hrmo', $existing->fresh()->role);
        $this->assertTrue($existing->fresh()->is_active);
        $this->assertTrue(Hash::check('ExistingPassword123!', $existing->fresh()->password));
    }

    public function test_configured_office_head_is_assigned_to_the_configured_existing_department(): void
    {
        $department = Department::create(['name' => 'Configured Office']);
        Config::set('hris.initial_accounts', [
            'office_head' => [
                'name' => 'Authorized Office Head',
                'email' => 'office-head@example.test',
                'password' => 'InitialPassword123!',
                'department_id' => $department->id,
            ],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);

        $user = User::where('email', 'office-head@example.test')->firstOrFail();
        $this->assertSame($user->id, $department->fresh()->head_user_id);
        $this->assertSame('office_head', $user->role);
    }

    public function test_existing_office_head_must_already_be_assigned_to_its_configured_department(): void
    {
        $department = Department::create(['name' => 'Configured Office']);
        $existing = User::factory()->create([
            'name' => 'Existing Office Head',
            'email' => 'existing-office-head@example.test',
            'password' => 'ExistingPassword123!',
            'role' => 'office_head',
            'is_active' => true,
        ]);
        Config::set('hris.initial_accounts', [
            'office_head' => [
                'name' => 'Configured Office Head',
                'email' => 'existing-office-head@example.test',
                'password' => 'InitialPassword123!',
                'department_id' => $department->id,
            ],
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('An existing Office Head account without the configured department assignment must be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('not assigned to its configured department', $exception->getMessage());
        }

        $this->assertNull($department->fresh()->head_user_id);
        $this->assertSame('office_head', $existing->fresh()->role);
        $this->assertTrue(Hash::check('ExistingPassword123!', $existing->fresh()->password));
    }

    public function test_configured_super_admin_is_created_once_without_overwriting_existing_account_data(): void
    {
        Config::set('hris.initial_accounts', [
            'super_admin' => [
                'name' => 'Authorized Super Admin',
                'email' => 'super-admin@example.test',
                'password' => 'InitialPassword123!',
            ],
        ]);

        $this->seed(ConfiguredAccountSeeder::class);
        $superAdmin = User::where('email', 'super-admin@example.test')->firstOrFail();
        $this->assertSame('super_admin', $superAdmin->role);

        $superAdmin->update([
            'name' => 'Updated Super Admin',
            'password' => 'ChangedPassword123!',
            'is_active' => false,
        ]);
        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('The seeder must report an existing inactive Super Admin account without changing it.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("role 'super_admin'", $exception->getMessage());
            $this->assertStringContainsString("status 'inactive'", $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Updated Super Admin', $superAdmin->fresh()->name);
        $this->assertSame('super_admin', $superAdmin->fresh()->role);
        $this->assertFalse($superAdmin->fresh()->is_active);
        $this->assertTrue(Hash::check('ChangedPassword123!', $superAdmin->fresh()->password));
    }

    public function test_super_admin_bootstrap_does_not_promote_an_existing_non_super_admin_account(): void
    {
        User::factory()->create(['email' => 'existing@example.test', 'role' => 'admin']);
        Config::set('hris.initial_accounts', [
            'super_admin' => [
                'name' => 'Existing Account',
                'email' => 'existing@example.test',
                'password' => 'InitialPassword123!',
            ],
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('A conflicting Super Admin bootstrap email must not silently change role.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("role 'admin'", $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['email' => 'existing@example.test', 'role' => 'admin']);
    }

    public function test_existing_configured_account_with_wrong_role_or_inactive_status_is_reported_without_changes(): void
    {
        $existing = User::factory()->create([
            'name' => 'Existing Employee',
            'email' => 'existing-admin@example.test',
            'password' => 'PreservedPassword123!',
            'role' => 'employee',
            'is_active' => false,
        ]);
        Config::set('hris.initial_accounts', [
            'admin' => [
                'name' => 'Configured Admin',
                'email' => 'existing-admin@example.test',
                'password' => 'InitialPassword123!',
            ],
        ]);

        try {
            $this->seed(ConfiguredAccountSeeder::class);
            $this->fail('The seeder must report role and status conflicts without changing the existing account.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("role 'employee'", $exception->getMessage());
            $this->assertStringContainsString("status 'inactive'", $exception->getMessage());
        }

        $this->assertSame('Existing Employee', $existing->fresh()->name);
        $this->assertSame('employee', $existing->fresh()->role);
        $this->assertFalse($existing->fresh()->is_active);
        $this->assertTrue(Hash::check('PreservedPassword123!', $existing->fresh()->password));
    }
}
