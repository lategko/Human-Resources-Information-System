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

        $this->seed(ConfiguredAccountSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Updated by Administrator', $user->fresh()->name);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertFalse($user->fresh()->can_final_approve);
        $this->assertTrue(Hash::check('ChangedPassword123!', $user->fresh()->password));
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
        $this->seed(ConfiguredAccountSeeder::class);

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
            $this->assertStringContainsString('already assigned', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['email' => 'existing@example.test', 'role' => 'admin']);
    }
}
