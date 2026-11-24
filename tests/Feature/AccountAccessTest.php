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

    public function test_login_password_keeps_text_show_hide_control(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('login-page-glass')
            ->assertSee('image/backgroundlanding.jpg')
            ->assertSee('Back to portal')
            ->assertDontSee('All Rights Reserved')
            ->assertSee('password-toggle')
            ->assertSee('x-text="showPassword ? \'Hide\' : \'Show\'"', false);
    }

    public function test_each_role_logs_in_to_its_assigned_dashboard_and_logout_invalidates_the_session(): void
    {
        $department = Department::create(['name' => 'Administration']);
        $accounts = collect(['admin', 'mayor', 'office_head', 'employee'])
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
        $department->update(['head_user_id' => $accounts['office_head']->id]);

        foreach ([
            'admin' => 'Administration overview',
            'mayor' => 'Municipal executive overview',
            'office_head' => 'Department overview',
            'employee' => 'My workspace',
        ] as $role => $dashboardHeading) {
            $this->post(route('login.store'), [
                'email' => $accounts[$role]->email,
                'password' => 'InitialPassword123!',
            ])->assertRedirect(route('dashboard'));

            $response = $this->get(route('dashboard'))->assertOk()->assertSee($dashboardHeading);
            preg_match('/<aside id="primary-navigation".*?>(.*?)<\/aside>/s', $response->getContent(), $sidebar);
            $this->assertArrayHasKey(1, $sidebar);
            $this->assertStringContainsString('data-lucide="layout-dashboard"', $sidebar[1]);
            $this->assertStringContainsString('sidebar-profile', $sidebar[1]);
            $this->assertStringContainsString($accounts[$role]->name, $sidebar[1]);
            $this->assertStringContainsString(match ($role) {
                'admin' => 'Administrator',
                'mayor' => 'Mayor',
                'office_head' => 'Office Head',
                'employee' => 'Employee / Staff',
            }, $sidebar[1]);
            $this->assertStringContainsString(match ($role) {
                'admin' => '<span>System settings</span>',
                'mayor' => '<span>Leave approvals</span>',
                'office_head' => '<span>My employees</span>',
                'employee' => '<span>My employee record</span>',
            }, $sidebar[1]);
            $restrictedRoute = match ($role) {
                'mayor' => route('users.index'),
                'office_head' => route('settings.system'),
                'employee' => route('reports.index'),
                default => null,
            };
            if ($restrictedRoute) {
                $this->assertStringNotContainsString('href="'.$restrictedRoute.'"', $sidebar[1]);
            }
            $this->assertStringContainsString('avatar-initials', $sidebar[1]);
            $this->assertStringContainsString('aria-current="page"', $sidebar[1]);
            $this->assertStringContainsString('aria-controls="primary-navigation"', $response->getContent());
            $this->assertStringContainsString('sidebarCollapsed = !sidebarCollapsed', $response->getContent());

            $sessionId = session()->getId();
            $this->post(route('logout'))->assertRedirect(route('login'));
            $this->assertGuest();
            $this->assertNotSame($sessionId, session()->getId());
        }
    }

    public function test_login_rejects_an_incorrect_password_and_unknown_email(): void
    {
        $user = User::factory()->create([
            'email' => 'authorized@example.test',
            'password' => 'CorrectPassword123!',
            'is_active' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'IncorrectPassword123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => 'unknown@example.test',
            'password' => 'CorrectPassword123!',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_office_head_can_only_review_employee_records_in_their_assigned_department(): void
    {
        $department = Department::create(['name' => 'Assigned Department']);
        $otherDepartment = Department::create(['name' => 'Other Department']);
        $officeHead = User::factory()->create(['role' => 'office_head', 'is_active' => true]);
        $department->update(['head_user_id' => $officeHead->id]);
        $assignedEmployee = Employee::create([
            'department_id' => $department->id,
            'employee_no' => 'EMP-ASSIGNED',
            'first_name' => 'Assigned',
            'last_name' => 'Employee',
        ]);
        $otherEmployee = Employee::create([
            'department_id' => $otherDepartment->id,
            'employee_no' => 'EMP-OTHER',
            'first_name' => 'Other',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($officeHead)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertSee($assignedEmployee->employee_no)
            ->assertDontSee($otherEmployee->employee_no);

        $this->get(route('employees.show', $otherEmployee))->assertForbidden();
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
        $mayor = User::factory()->create(['role' => 'mayor', 'is_active' => true]);
        $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);

        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->get(route('settings.system'))->assertOk();
        $this->actingAs($mayor)->get(route('settings.system'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('users.index'))->assertForbidden();
        $this->actingAs($employee)->get(route('users.create'))->assertForbidden();
    }

    public function test_admin_cannot_assign_legacy_or_unknown_roles(): void
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
            'name' => 'Legacy Super Admin',
            'email' => 'legacy-role@example.test',
            'role' => 'super_admin',
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
        $this->assertDatabaseMissing('users', ['email' => 'legacy-role@example.test']);
    }

    public function test_admin_final_approval_permission_is_limited_to_mayor_personal_leave(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'can_final_approve' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administration overview')
            ->assertSee('data-role-theme="administrator"', false)
            ->assertSee('Administrator');
        $this->assertTrue($admin->canApproveMayorLeave());
        $this->assertFalse($admin->canFinalApprove());
    }

    public function test_admin_role_changes_prevent_a_second_administrator_and_preserve_employee_links(): void
    {
        $department = Department::create(['name' => 'Human Resources']);
        $adminActor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
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

        $this->actingAs($adminActor)->put(route('users.update', $employeeUser), [
            'name' => $employeeUser->name,
            'email' => $employeeUser->email,
            'role' => 'admin',
            'is_active' => 1,
        ])->assertSessionHasErrors('role');
        $this->assertSame('employee', $employeeUser->fresh()->role);
        $this->assertSame($employee->id, $employeeUser->fresh()->employee->id);

        $this->put(route('users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'employee',
            'employee_id' => $unlinkedEmployee->id,
            'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('employee', $admin->fresh()->role);
        $this->assertSame($unlinkedEmployee->id, $admin->fresh()->employee->id);
        $this->assertSame('New Employee', $admin->fresh()->name);
    }

    public function test_configured_accounts_page_filters_users_and_shows_department_and_last_login(): void
    {
        $department = Department::create(['name' => 'Public Works']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@example.test',
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
            'email' => $admin->email,
            'password' => 'InitialPassword123!',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('users.index'))
            ->assertOk()
            ->assertSee('User accounts')
            ->assertSee('employee@example.test')
            ->assertSee('Public Works');
    }

    public function test_account_edit_updates_the_existing_user_and_preserves_its_employee_relationship(): void
    {
        $department = Department::create(['name' => 'Finance']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $account = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $employee = Employee::create([
            'user_id' => $account->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-005',
            'first_name' => 'Existing',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($admin)->put(route('users.update', $account), [
            'name' => $account->name,
            'email' => 'updated-admin@example.test',
            'role' => 'employee',
            'employee_id' => $employee->id,
            'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'email' => 'updated-admin@example.test',
            'role' => 'employee',
        ]);
        $this->assertSame($account->id, $employee->fresh()->user_id);
        $this->assertSame(1, User::where('email', 'updated-admin@example.test')->count());
    }

    public function test_employee_search_is_limited_to_twenty_results_and_matches_employee_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        for ($number = 1; $number <= 21; $number++) {
            Employee::create([
                'employee_no' => sprintf('SEARCH-%03d', $number),
                'first_name' => 'Search',
                'last_name' => sprintf('Worker%03d', $number),
            ]);
        }

        $firstPage = $this->actingAs($admin)
            ->getJson(route('employees.search'))
            ->assertOk()
            ->assertJsonCount(20, 'results')
            ->assertJsonPath('has_more', true);
        $this->assertCount(1, $this->getJson(route('employees.search', ['page' => 2]))->assertOk()->json('results'));

        $firstPage->assertJsonPath('results.0.employee_no', 'SEARCH-001');
        $this->getJson(route('employees.search', ['q' => 'SEARCH-021']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.employee_no', 'SEARCH-021');
    }

    public function test_employee_search_scopes_office_heads_to_their_department(): void
    {
        $officeHead = User::factory()->create(['role' => 'office_head', 'is_active' => true]);
        $department = Department::create(['name' => 'Assigned Office', 'head_user_id' => $officeHead->id]);
        $otherDepartment = Department::create(['name' => 'Other Office']);
        Employee::create([
            'department_id' => $department->id,
            'employee_no' => 'SCOPE-IN',
            'first_name' => 'Assigned',
            'last_name' => 'Employee',
        ]);
        Employee::create([
            'department_id' => $otherDepartment->id,
            'employee_no' => 'SCOPE-OUT',
            'first_name' => 'Other',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($officeHead)
            ->getJson(route('employees.search'))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.employee_no', 'SCOPE-IN');
    }

    public function test_employee_search_only_includes_a_linked_employee_for_its_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $account = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $otherAccount = User::factory()->create(['role' => 'employee', 'is_active' => true]);
        $linkedEmployee = Employee::create([
            'user_id' => $account->id,
            'employee_no' => 'LINKED-SELF',
            'first_name' => 'Linked',
            'last_name' => 'Self',
        ]);
        Employee::create([
            'user_id' => $otherAccount->id,
            'employee_no' => 'LINKED-OTHER',
            'first_name' => 'Linked',
            'last_name' => 'Other',
        ]);
        Employee::create([
            'employee_no' => 'UNLINKED',
            'first_name' => 'Unlinked',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($admin)
            ->getJson(route('employees.search', [
                'unlinked_only' => 1,
                'target_user_id' => $account->id,
                'include_employee_id' => $linkedEmployee->id,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'results')
            ->assertJsonMissing(['employee_no' => 'LINKED-OTHER']);
    }
}
