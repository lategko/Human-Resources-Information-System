<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_logs_in_to_its_assigned_dashboard_and_logout_invalidates_the_session(): void
    {
        $department = Department::create(['name' => 'Administration']);
        $accounts = collect(['super_admin', 'admin', 'hrmo', 'employee'])
            ->mapWithKeys(fn (string $role) => [
                $role => User::factory()->create([
                    'name' => ucfirst($role).' User',
                    'email' => $role.'@example.test',
                    'role' => $role,
                    'password' => 'InitialPassword123!',
                    'is_active' => true,
                ]),
            ]);
        Employee::create([
            'user_id' => $accounts['employee']->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-001',
            'first_name' => 'Employee',
            'last_name' => 'User',
        ]);

        foreach ([
            'super_admin' => 'Super Admin overview',
            'admin' => 'Administration overview',
            'hrmo' => 'HR operations',
            'employee' => 'My workspace',
        ] as $role => $dashboardHeading) {
            $this->post(route('login.store'), [
                'email' => $accounts[$role]->email,
                'password' => 'InitialPassword123!',
            ])->assertRedirect(route('dashboard'));

            $this->get(route('dashboard'))->assertOk()->assertSee($dashboardHeading);

            $sessionId = session()->getId();
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
            $this->assertNotSame($sessionId, session()->getId());
        }
    }

    public function test_inactive_accounts_cannot_log_in_and_restricted_pages_reject_direct_requests(): void
    {
        $inactive = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'InitialPassword123!',
            'is_active' => false,
        ]);
        $this->post(route('login.store'), [
            'email' => $inactive->email,
            'password' => 'InitialPassword123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $invalidRole = User::factory()->create([
            'email' => 'invalid-role@example.test',
            'password' => 'InitialPassword123!',
            'role' => 'unrecognized',
            'is_active' => true,
        ]);
        $this->post(route('login.store'), [
            'email' => $invalidRole->email,
            'password' => 'InitialPassword123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $hrmo = User::factory()->create(['role' => 'hrmo', 'is_active' => true]);
        $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);

        $this->actingAs($admin)->get(route('super-admin.users.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.system'))->assertForbidden();
        $this->actingAs($hrmo)->get(route('settings.system'))->assertForbidden();
        $this->actingAs($employee)->get(route('super-admin.users.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('users.create'))->assertForbidden();
    }

    public function test_admin_cannot_assign_hrmo_role_or_change_an_existing_accounts_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $employeeUser = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
        ]);
        $department = Department::create(['name' => 'Operations']);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-006',
            'first_name' => 'Role',
            'last_name' => 'Protected',
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Unauthorized HRMO',
            'email' => 'unauthorized-hrmo@example.test',
            'role' => 'hrmo',
            'is_active' => 1,
            'password' => 'InitialPassword123!',
            'password_confirmation' => 'InitialPassword123!',
        ])->assertSessionHasErrors('role');

        $this->put(route('users.update', $employeeUser), [
            'name' => 'Role Protected',
            'email' => $employeeUser->email,
            'role' => 'hrmo',
            'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertSame('employee', $employeeUser->fresh()->role);
        $this->assertSame($employeeUser->id, $employee->fresh()->user_id);
        $this->assertDatabaseMissing('users', ['email' => 'unauthorized-hrmo@example.test']);
    }

    public function test_admin_final_approval_permission_keeps_its_legacy_dashboard_and_shows_its_primary_role(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'can_final_approve' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Mayor’s executive overview')
            ->assertSee('data-role-theme="mayor"', false)
            ->assertSee('ADMIN · FINAL APPROVER');
    }

    public function test_super_admin_role_changes_preserve_employee_links_and_can_demote_admin_to_employee(): void
    {
        $department = Department::create(['name' => 'Human Resources']);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $employeeUser = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $employee = Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-002',
            'first_name' => 'Linked',
            'last_name' => 'Employee',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $unlinkedEmployee = Employee::create([
            'department_id' => $department->id,
            'employee_no' => 'EMP-003',
            'first_name' => 'New',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($superAdmin)
            ->patch(route('super-admin.users.role', $employeeUser), ['role' => 'hrmo'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('hrmo', $employeeUser->fresh()->role);
        $this->assertSame($employee->id, $employeeUser->fresh()->employee->id);

        $this->patch(route('super-admin.users.role', $admin), [
            'role' => 'employee',
            'employee_id' => $unlinkedEmployee->id,
        ])->assertRedirect();
        $this->assertSame('employee', $admin->fresh()->role);
        $this->assertSame($unlinkedEmployee->id, $admin->fresh()->employee->id);
        $this->assertSame('New Employee', $admin->fresh()->name);
    }

    public function test_configured_accounts_page_filters_users_and_shows_department_and_last_login(): void
    {
        $department = Department::create(['name' => 'Public Works']);
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'email' => 'super@example.test',
            'password' => 'InitialPassword123!',
            'is_active' => true,
        ]);
        $employeeUser = User::factory()->create([
            'role' => 'employee',
            'email' => 'employee@example.test',
            'password' => 'InitialPassword123!',
            'is_active' => true,
        ]);
        Employee::create([
            'user_id' => $employeeUser->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-004',
            'first_name' => 'Public',
            'last_name' => 'Worker',
        ]);

        $this->post(route('login.store'), [
            'email' => $employeeUser->email,
            'password' => 'InitialPassword123!',
        ])->assertRedirect(route('dashboard'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $superAdmin->email,
            'password' => 'InitialPassword123!',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('super-admin.users.index', [
            'search' => 'employee@example.test',
            'role' => 'employee',
            'status' => 'active',
        ]))
            ->assertOk()
            ->assertSee('Configured accounts')
            ->assertSee('employee@example.test')
            ->assertSee('Public Works')
            ->assertSee(now()->format('M j, Y'))
            ->assertDontSee('Never');
    }

    public function test_account_edit_updates_the_existing_user_and_preserves_its_employee_relationship(): void
    {
        $department = Department::create(['name' => 'Finance']);
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $account = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $employee = Employee::create([
            'user_id' => $account->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-005',
            'first_name' => 'Existing',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($superAdmin)->put(route('users.update', $account), [
            'name' => 'Updated HRMO',
            'email' => 'updated-hrmo@example.test',
            'role' => 'hrmo',
            'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'email' => 'updated-hrmo@example.test',
            'role' => 'hrmo',
        ]);
        $this->assertSame($account->id, $employee->fresh()->user_id);
        $this->assertSame(1, User::where('email', 'updated-hrmo@example.test')->count());
    }
}
