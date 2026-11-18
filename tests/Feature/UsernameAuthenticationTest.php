<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeUsernameGenerator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UsernameAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_administrator_can_sign_in_with_normalized_email_and_employee_with_username(): void
    {
        $admin = $this->createUser('ADMIN@EXAMPLE.TEST', null, 'admin');
        $employee = $this->createUser(null, 'Juan.DelaCruz', 'employee');

        $this->post(route('login.store'), [
            'login' => '  ADMIN@EXAMPLE.TEST ',
            'password' => 'SecurePassword123!',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'login' => 'JUAN.DELACRUZ',
            'password' => 'SecurePassword123!',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($employee);
    }

    public function test_first_login_requires_setup_and_prevents_employee_data_or_permission_changes(): void
    {
        $user = $this->createUser(null, 'juan.delacruz', 'employee', [
            'must_change_password' => true,
            'account_setup_completed' => false,
        ]);
        $department = Department::create(['name' => 'HR Controlled Office']);
        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-SETUP-1',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);

        $this->post(route('login.store'), [
            'login' => $user->username,
            'password' => 'SecurePassword123!',
        ])->assertRedirect(route('account.setup'));
        $this->get(route('dashboard'))->assertRedirect(route('account.setup'));

        $this->put(route('account.setup.update'), [
            'username' => 'juan.delacruz.1',
            'email' => '',
            'current_password' => 'SecurePassword123!',
            'new_password' => 'NewSecurePassword123!',
            'new_password_confirmation' => 'NewSecurePassword123!',
            'contact_number' => '09171234567',
            'address' => 'Camalaniugan',
            'role' => 'admin',
            'department_id' => 999,
            'employee_no' => 'TAMPERED',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame('juan.delacruz.1', $user->username);
        $this->assertSame('employee', $user->role);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->account_setup_completed);
        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->password));
        $this->assertSame('EMP-SETUP-1', $employee->fresh()->employee_no);
        $this->assertSame($department->id, $employee->fresh()->department_id);
        $this->assertSame('09171234567', $employee->fresh()->contact_number);
    }

    public function test_admin_is_exempt_from_first_login_setup_and_keeps_profile_access(): void
    {
        $admin = $this->createUser('hr-admin@example.test', null, 'admin', [
            'must_change_password' => true,
            'account_setup_completed' => false,
        ]);

        $this->post(route('login.store'), [
            'login' => $admin->email,
            'password' => 'SecurePassword123!',
        ])->assertRedirect(route('dashboard'));

        $this->get(route('account.setup'))->assertRedirect(route('dashboard'));
        $this->get(route('account.profile'))->assertOk();
        $this->get(route('account.profile.edit'))->assertOk();
    }

    public function test_employees_can_register_email_and_verify_it_with_a_signed_link(): void
    {
        Notification::fake();
        $user = $this->createUser(null, 'employee.profile', 'employee');
        $department = Department::create(['name' => 'Profile Office']);
        Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-PROFILE-1',
            'first_name' => 'Profile',
            'last_name' => 'Employee',
        ]);

        $this->actingAs($user)->put(route('account.profile.update'), [
            'username' => 'Employee.Profile',
            'current_password' => 'SecurePassword123!',
            'email' => 'NEW.Employee@Example.Test',
            'contact_number' => '09170000000',
            'address' => 'Municipality',
            'role' => 'admin',
            'department_id' => 999,
        ])->assertRedirect(route('account.profile'));

        $user->refresh();
        $this->assertSame('employee.profile', $user->username);
        $this->assertSame('new.employee@example.test', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
        $this->get($verificationUrl)->assertRedirect(route('account.profile'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('employee', $user->fresh()->role);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'login' => 'NEW.EMPLOYEE@EXAMPLE.TEST',
            'password' => 'SecurePassword123!',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_cannot_sign_in_with_username_or_email(): void
    {
        $user = $this->createUser('inactive@example.test', 'inactive.user', 'employee', ['is_active' => false]);

        foreach ([$user->username, $user->email] as $login) {
            $this->post(route('login.store'), [
                'login' => $login,
                'password' => 'SecurePassword123!',
            ])->assertSessionHasErrors('login');
            $this->assertGuest();
        }
    }

    public function test_hr_can_create_an_employee_account_and_send_a_secure_activation_link(): void
    {
        Notification::fake();
        $admin = $this->createUser('hr@example.test', null, 'admin');
        $department = Department::create(['name' => 'Account Office']);
        $employee = Employee::create([
            'department_id' => $department->id,
            'employee_no' => 'EMP-ACCOUNT-1',
            'first_name' => 'Maria',
            'middle_name' => 'Luz',
            'last_name' => 'Santos',
            'employment_status' => 'Active',
        ]);
        $this->actingAs($admin)
            ->get(route('users.index', ['search' => 'EMP-ACCOUNT-1']))
            ->assertOk()
            ->assertSee('Employees without linked accounts')
            ->assertSee('Create account');

        $this->post(route('users.store'), [
            'role' => 'employee',
            'employee_id' => $employee->id,
            'email' => 'Maria.Santos@example.test',
            'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $user = $employee->fresh()->user;
        $this->assertSame('maria.luz.santos', $user->username);
        $this->assertSame('maria.santos@example.test', $user->email);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->account_setup_completed);
        $this->assertFalse(Hash::check('password', $user->password));
        Notification::assertSentTo($user, ResetPassword::class);

        $this->post(route('users.activation-link', $user))
            ->assertRedirect();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_hr_can_reveal_a_one_time_temporary_password_without_persisting_plaintext(): void
    {
        $admin = $this->createUser('hr@example.test', 'hr.admin', 'admin');
        $user = $this->createUser(null, 'employee.no.email', 'employee', [
            'must_change_password' => true,
            'account_setup_completed' => false,
        ]);
        $department = Department::create(['name' => 'Temporary Credential Office']);
        Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-TEMP-1',
            'first_name' => 'Temporary',
            'last_name' => 'Employee',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('users.temporary-credential', $user));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertViewHas('temporaryPassword', function (string $temporaryPassword) use ($user): bool {
                $this->assertGreaterThanOrEqual(32, strlen($temporaryPassword));
                $this->assertTrue(Hash::check($temporaryPassword, $user->fresh()->password));

                return true;
            });

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->account_setup_completed);
        $this->assertSame('employee', $user->role);
    }

    public function test_temporary_credentials_cannot_be_generated_for_non_employee_accounts(): void
    {
        $admin = $this->createUser('hr@example.test', 'hr.admin', 'admin');
        $otherAdmin = $this->createUser('another-admin@example.test', 'another.admin', 'admin');
        $originalPassword = $otherAdmin->password;

        $this->actingAs($admin)
            ->post(route('users.temporary-credential', $otherAdmin))
            ->assertForbidden();

        $this->assertSame($originalPassword, $otherAdmin->fresh()->password);
    }

    public function test_username_generation_appends_employee_number_for_duplicate_names(): void
    {
        $this->createUser(null, 'juan.delacruz', 'employee');

        $username = app(EmployeeUsernameGenerator::class)
            ->generate('Juan Dela Cruz', 'EMP-001');

        $this->assertSame('juan.delacruz2', $username);
    }

    public function test_username_generation_preserves_middle_names_and_suffixes(): void
    {
        $username = app(EmployeeUsernameGenerator::class)->generateForNameParts(
            'Juan',
            'M.',
            'Dela Cruz',
            'Jr.',
            'EMP-002'
        );

        $this->assertSame('juan.m.delacruz.jr', $username);
    }

    public function test_administrator_cannot_create_a_second_system_administrator(): void
    {
        $admin = $this->createUser('hr-admin@example.test', 'hr.admin', 'admin');

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Second Administrator',
            'first_name' => 'Second',
            'last_name' => 'Administrator',
            'email' => 'second.admin@example.test',
            'username' => 'second.admin',
            'role' => 'admin',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'is_active' => 1,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, User::query()->where('role', 'admin')->count());
    }

    public function test_duplicate_usernames_and_emails_are_rejected_after_normalization(): void
    {
        $user = $this->createUser('used@example.test', 'used.name', 'employee');
        $other = $this->createUser(null, 'other.name', 'employee');

        $this->actingAs($other)->put(route('account.profile.update'), [
            'username' => 'USED.NAME',
            'email' => 'USED@EXAMPLE.TEST',
            'current_password' => 'SecurePassword123!',
        ])->assertSessionHasErrors(['username', 'email']);
        $this->assertSame('other.name', $other->fresh()->username);
        $this->assertNull($other->fresh()->email);
        $this->assertSame('used.name', $user->fresh()->username);
    }

    public function test_legacy_personal_contact_route_cannot_change_hr_employee_email(): void
    {
        $user = $this->createUser('employee@example.test', 'contact.employee', 'employee');
        $department = Department::create(['name' => 'Contact Office']);
        $employee = Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'EMP-CONTACT-1',
            'first_name' => 'Contact',
            'last_name' => 'Employee',
            'email' => 'hr-record@example.test',
        ]);

        $this->actingAs($user)->put(route('my-profile.update'), [
            'email' => 'unverified@example.test',
            'contact_number' => '09171111111',
        ])->assertRedirect();

        $this->assertSame('hr-record@example.test', $employee->fresh()->email);
        $this->assertSame('09171111111', $employee->fresh()->contact_number);
    }

    private function createUser(?string $email, ?string $username, string $role, array $extra = []): User
    {
        return User::create([
            'name' => ucfirst($role).' Test Account',
            'email' => $email,
            'username' => $username,
            'password' => 'SecurePassword123!',
            'role' => $role,
            'is_active' => true,
            ...$extra,
        ]);
    }
}
