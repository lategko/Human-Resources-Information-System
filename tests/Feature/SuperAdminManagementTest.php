<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuperAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_access_super_admin_management(): void
    {
        $superAdmin = $this->createUser('super_admin');
        $admin = $this->createUser('admin');
        $hrmo = $this->createUser('hrmo');

        $this->actingAs($superAdmin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Super Admin overview')
            ->assertSee('Manage accounts');
        $this->get(route('super-admin.users.index'))->assertOk();

        $this->actingAs($admin)->get(route('super-admin.users.index'))->assertForbidden();
        $this->actingAs($hrmo)->get(route('super-admin.users.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('super-admin.users.show', $hrmo))->assertForbidden();
    }

    public function test_super_admin_can_promote_and_demote_accounts_with_audit_history(): void
    {
        $superAdmin = $this->createUser('super_admin');
        $hrmo = $this->createUser('hrmo');
        $admin = $this->createUser('admin');

        $this->actingAs($superAdmin)->from(route('super-admin.users.show', $hrmo))
            ->patch(route('super-admin.users.role', $hrmo), ['role' => 'admin'])
            ->assertRedirect(route('super-admin.users.show', $hrmo));
        $this->assertSame('admin', $hrmo->fresh()->role);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'action' => 'user.role_changed',
            'subject_id' => $hrmo->id,
        ]);
        $this->assertSame('hrmo', AuditLog::where('subject_id', $hrmo->id)->latest()->firstOrFail()->metadata['previous_role']);
        $this->assertSame('admin', AuditLog::where('subject_id', $hrmo->id)->latest()->firstOrFail()->metadata['new_role']);

        $this->from(route('super-admin.users.show', $admin))
            ->patch(route('super-admin.users.role', $admin), ['role' => 'hrmo'])
            ->assertRedirect(route('super-admin.users.show', $admin));
        $this->assertSame('hrmo', $admin->fresh()->role);
        $this->assertFalse($admin->fresh()->can_final_approve);
    }

    public function test_only_super_admin_can_assign_and_remove_the_super_admin_role(): void
    {
        $superAdmin = $this->createUser('super_admin');
        $admin = $this->createUser('admin');

        $this->actingAs($superAdmin)->patch(route('super-admin.users.role', $admin), ['role' => 'super_admin'])
            ->assertRedirect();
        $this->assertSame('super_admin', $admin->fresh()->role);
        $this->assertSame('admin', AuditLog::where('subject_id', $admin->id)->latest()->firstOrFail()->metadata['previous_role']);
        $this->assertSame('super_admin', AuditLog::where('subject_id', $admin->id)->latest()->firstOrFail()->metadata['new_role']);

        $this->patch(route('super-admin.users.role', $admin), ['role' => 'admin'])->assertRedirect();
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_admin_cannot_create_or_assign_an_admin_through_regular_user_management(): void
    {
        $admin = $this->createUser('admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Unapproved Administrator',
            'first_name' => 'Unapproved',
            'last_name' => 'Administrator',
            'email' => 'unapproved@example.test',
            'role' => 'admin',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'unapproved@example.test']);
    }

    public function test_super_admin_can_create_a_regular_admin_account_and_audit_its_creation(): void
    {
        $superAdmin = $this->createUser('super_admin');

        $this->actingAs($superAdmin)->post(route('users.store'), [
            'name' => 'Municipal Administrator',
            'first_name' => 'Municipal',
            'last_name' => 'Administrator',
            'email' => 'municipal-admin@example.test',
            'role' => 'admin',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertRedirect(route('users.index'));

        $created = User::where('email', 'municipal-admin@example.test')->firstOrFail();
        $this->assertSame('admin', $created->role);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'action' => 'user.created',
            'subject_id' => $created->id,
        ]);
    }

    public function test_super_admin_cannot_promote_self_or_assign_super_admin_through_user_management(): void
    {
        $superAdmin = $this->createUser('super_admin');

        $this->actingAs($superAdmin)->patch(route('super-admin.users.role', $superAdmin), ['role' => 'admin'])
            ->assertForbidden();
        $this->assertSame('super_admin', $superAdmin->fresh()->role);

        $this->post(route('users.store'), [
            'name' => 'Second Super Admin',
            'first_name' => 'Second',
            'last_name' => 'Super Admin',
            'email' => 'second-root@example.test',
            'role' => 'super_admin',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'second-root@example.test']);
    }

    public function test_super_admin_cannot_deactivate_self_and_keeps_another_active_super_admin_protected(): void
    {
        $superAdmin = $this->createUser('super_admin');

        $this->actingAs($superAdmin)->patch(route('super-admin.users.status', $superAdmin))
            ->assertForbidden();
        $this->assertTrue($superAdmin->fresh()->is_active);

        $otherSuperAdmin = $this->createUser('super_admin');
        $this->from(route('super-admin.users.show', $otherSuperAdmin))
            ->patch(route('super-admin.users.status', $otherSuperAdmin))
            ->assertRedirect(route('super-admin.users.show', $otherSuperAdmin));
        $this->assertFalse($otherSuperAdmin->fresh()->is_active);
        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_super_admin_can_reset_other_account_password_and_audit_without_logging_secret(): void
    {
        $superAdmin = $this->createUser('super_admin');
        $admin = $this->createUser('admin');
        $rememberToken = $admin->remember_token;

        $this->actingAs($superAdmin)->put(route('super-admin.users.password', $admin), [
            'password' => 'ResetPassword123!',
            'password_confirmation' => 'ResetPassword123!',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('ResetPassword123!', $admin->fresh()->password));
        $this->assertNotSame($rememberToken, $admin->fresh()->remember_token);
        $event = AuditLog::where('action', 'user.credentials_reset')->firstOrFail();
        $this->assertSame($superAdmin->id, $event->user_id);
        $this->assertSame($admin->id, $event->subject_id);
        $this->assertStringNotContainsString('ResetPassword123!', json_encode($event->metadata));
    }

    public function test_new_mayor_role_keeps_final_approval_access_without_admin_management_access(): void
    {
        $mayor = $this->createUser('mayor');

        $this->assertTrue($mayor->canFinalApprove());
        $this->actingAs($mayor)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Municipal overview');
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('departments.index'))->assertOk();
        $this->get(route('users.create'))->assertForbidden();
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
