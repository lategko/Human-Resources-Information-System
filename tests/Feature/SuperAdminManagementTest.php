<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SuperAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_four_operational_roles_exist_and_super_admin_routes_are_removed(): void
    {
        $this->assertSame(['admin', 'mayor', 'office_head', 'employee'], User::VALID_ROLES);
        $this->assertFalse(Route::has('super-admin.users.index'));
        $this->assertFalse(Route::has('super-admin.users.role'));
        $this->assertFalse(Route::has('super-admin.users.status'));
    }

    public function test_admin_can_create_accounts_and_manage_system_settings(): void
    {
        $admin = $this->createUser('admin');

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->get(route('settings.system'))->assertOk();
        $this->post(route('users.store'), [
            'name' => 'Municipal Mayor',
            'first_name' => 'Municipal',
            'last_name' => 'Mayor',
            'email' => 'municipal-mayor@example.test',
            'role' => 'mayor',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertRedirect(route('users.index'));

        $created = User::where('email', 'municipal-mayor@example.test')->firstOrFail();
        $this->assertSame('mayor', $created->role);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'user.created',
            'subject_id' => $created->id,
        ]);
    }

    public function test_admin_may_assign_only_canonical_roles(): void
    {
        $admin = $this->createUser('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Legacy Super Admin',
            'first_name' => 'Legacy',
            'last_name' => 'Administrator',
            'email' => 'legacy-role@example.test',
            'role' => 'super_admin',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'legacy-role@example.test']);
    }

    public function test_admin_final_approver_flag_does_not_change_the_admin_role(): void
    {
        $admin = $this->createUser('admin');
        $admin->update(['can_final_approve' => true]);

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue($admin->isAdministrator());
        $this->assertTrue($admin->canApproveMayorLeave());
        $this->assertFalse($admin->canFinalApprove());
    }

    public function test_mayor_has_approval_and_oversight_access_but_no_system_administration(): void
    {
        $mayor = $this->createUser('mayor');

        $this->assertTrue($mayor->canFinalApprove());
        $this->actingAs($mayor)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Municipal executive overview');
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('departments.index'))->assertOk();
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->get(route('settings.system'))->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
    }

    private function createUser(string $role): User
    {
        return User::factory()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)).' Account',
            'email' => $role.'-'.uniqid().'@example.test',
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
