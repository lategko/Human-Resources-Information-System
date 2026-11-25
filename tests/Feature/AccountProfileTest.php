<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmailVerificationOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_can_access_their_own_profile_and_edit_form(): void
    {
        foreach (['admin', 'mayor', 'office_head', 'employee'] as $role) {
            $user = $this->createUser($role);

            $this->actingAs($user)
                ->get(route('account.profile'))
                ->assertOk()
                ->assertSee($user->email);

            $this->get(route('account.profile.edit'))->assertOk();
        }
    }

    public function test_non_employee_roles_can_update_their_own_account_details_without_changing_role(): void
    {
        Notification::fake();

        foreach (['office_head', 'mayor'] as $role) {
            $user = $this->createUser($role);

            $this->actingAs($user)->put(route('account.profile.update'), [
                'name' => 'Updated '.ucfirst($role),
                'current_password' => 'CurrentPassword123!',
                'email' => 'updated-'.$role.'@example.test',
                'role' => 'employee',
                'is_active' => false,
                'can_final_approve' => false,
            ])->assertRedirect(route('account.profile'));

            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'name' => 'Updated '.ucfirst($role),
                'email' => 'updated-'.$role.'@example.test',
                'role' => $role,
                'is_active' => true,
                'can_final_approve' => false,
            ]);
        }
    }

    public function test_admin_email_changes_require_password_and_keep_the_old_email_until_otp_verification(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin', 'CurrentPassword123!');
        $verifiedAt = now()->subDays(3);
        $admin->forceFill(['email_verified_at' => $verifiedAt])->save();
        $newEmail = 'new-admin-address@example.test';
        $this->actingAs($admin);

        $this->put(route('account.profile.update'), [
            'name' => $admin->name,
            'email' => $newEmail,
        ])->assertSessionHasErrors('current_password');

        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertSame($verifiedAt->timestamp, $admin->fresh()->email_verified_at->timestamp);
        Notification::assertNothingSent();

        $this->put(route('account.profile.update'), [
            'name' => $admin->name,
            'email' => $newEmail,
            'current_password' => 'CurrentPassword123!',
        ])->assertRedirect(route('account.profile.edit'));

        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertSame($verifiedAt->timestamp, $admin->fresh()->email_verified_at->timestamp);
        $this->get(route('account.profile.edit'))
            ->assertOk()
            ->assertSee($newEmail)
            ->assertSee('Your current address');

        $code = null;
        Notification::assertSentOnDemand(EmailVerificationOtp::class, function ($notification, $channels, $notifiable) use (&$code, $newEmail): bool {
            if (($notifiable->routes['mail'] ?? null) !== $newEmail) {
                return false;
            }

            $code = $notification->code;

            return true;
        });
        $this->assertNotNull($code);

        $this->post(route('account.profile.email-change.verify'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertSame($admin->email, $admin->fresh()->email);

        $this->post(route('account.profile.email-change.verify'), ['code' => $code])
            ->assertRedirect(route('account.profile.edit'));
        $this->assertSame($newEmail, $admin->fresh()->email);
        $this->assertNotNull($admin->fresh()->email_verified_at);

        $this->post(route('account.profile.email-change.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertSame($newEmail, $admin->fresh()->email);
    }

    public function test_admin_email_change_resend_replaces_old_code_and_is_rate_limited(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin');
        $newEmail = 'resend-admin@example.test';
        $this->actingAs($admin);
        $this->put(route('account.profile.update'), [
            'name' => $admin->name,
            'email' => $newEmail,
            'current_password' => 'CurrentPassword123!',
        ])->assertRedirect(route('account.profile.edit'));

        $codes = [];
        $captureCode = function ($notification, $channels, $notifiable) use (&$codes, $newEmail): bool {
            if (($notifiable->routes['mail'] ?? null) !== $newEmail) {
                return false;
            }

            $codes[] = $notification->code;

            return true;
        };
        Notification::assertSentOnDemand(EmailVerificationOtp::class, $captureCode);
        $firstCode = $codes[0];

        $this->post(route('account.profile.email-change.resend'))
            ->assertRedirect();
        $codes = [];
        Notification::sent(new AnonymousNotifiable(), EmailVerificationOtp::class, $captureCode);
        $secondCode = $codes[1];

        $this->post(route('account.profile.email-change.verify'), ['code' => $firstCode])
            ->assertSessionHasErrors('code');
        $this->assertSame($admin->email, $admin->fresh()->email);

        $this->post(route('account.profile.email-change.resend'))
            ->assertRedirect();
        $codes = [];
        Notification::sent(new AnonymousNotifiable(), EmailVerificationOtp::class, $captureCode);
        $latestCode = $codes[count($codes) - 1];

        $this->post(route('account.profile.email-change.resend'))
            ->assertStatus(429);
        $this->post(route('account.profile.email-change.verify'), ['code' => $latestCode])
            ->assertRedirect(route('account.profile.edit'));
        $this->assertSame($newEmail, $admin->fresh()->email);
        $this->assertNotSame($firstCode, $secondCode);
    }

    public function test_admin_email_change_code_expires_after_ten_minutes(): void
    {
        Notification::fake();
        $admin = $this->createUser('admin');
        $newEmail = 'expired-admin@example.test';
        $this->actingAs($admin);
        $this->put(route('account.profile.update'), [
            'name' => $admin->name,
            'email' => $newEmail,
            'current_password' => 'CurrentPassword123!',
        ])->assertRedirect(route('account.profile.edit'));

        $code = null;
        Notification::assertSentOnDemand(EmailVerificationOtp::class, function ($notification, $channels, $notifiable) use (&$code, $newEmail): bool {
            if (($notifiable->routes['mail'] ?? null) !== $newEmail) {
                return false;
            }

            $code = $notification->code;

            return true;
        });

        $this->travel(11)->minutes();
        $this->post(route('account.profile.email-change.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->assertNull($admin->fresh()->email_verified_at);
    }

    public function test_linked_employee_can_update_only_contact_details_without_changing_permissions(): void
    {
        $user = $this->createUser('employee');
        $employee = $this->createEmployee($user);

        $this->actingAs($user)->put(route('account.profile.update'), [
            'first_name' => 'Updated',
            'middle_name' => 'Maria',
            'last_name' => 'Employee',
            'suffix' => 'Jr.',
            'sex' => 'Female',
            'date_of_birth' => '1990-04-12',
            'contact_number' => '09171234567',
            'address' => 'New residential address',
            'username' => 'updated.employee',
            'current_password' => 'CurrentPassword123!',
            'email' => 'updated@example.test',
            'role' => 'admin',
            'is_active' => false,
            'can_final_approve' => true,
        ])->assertRedirect(route('account.profile'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => 'Employee',
            'contact_number' => '09171234567',
            'address' => 'New residential address',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Employee Test User',
            'email' => 'updated@example.test',
            'role' => 'employee',
            'is_active' => true,
            'can_final_approve' => false,
        ]);
    }

    public function test_unlinked_user_can_update_personal_name_and_contact_information_without_changing_role(): void
    {
        $user = $this->createUser('admin');

        $this->actingAs($user)->put(route('account.profile.update'), [
            'name' => 'Updated Human Resources Officer',
            'first_name' => 'Updated',
            'middle_name' => 'Human',
            'last_name' => 'Resources Officer',
            'suffix' => '',
            'profile_gender' => 'Prefer not to say',
            'profile_birthdate' => '1990-04-12',
            'profile_contact_number' => '09171234567',
            'profile_address' => 'Municipal address',
            'current_password' => 'CurrentPassword123!',
            'email' => $user->email,
            'role' => 'admin',
        ])->assertRedirect(route('account.profile'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Human Resources Officer',
            'email' => $user->email,
            'role' => 'admin',
            'first_name' => 'Updated',
            'middle_name' => 'Human',
            'last_name' => 'Resources Officer',
            'profile_gender' => 'Prefer not to say',
            'profile_contact_number' => '09171234567',
            'profile_address' => 'Municipal address',
        ]);
    }

    public function test_user_cannot_update_another_account_through_personal_profile_routes(): void
    {
        $user = $this->createUser('admin');
        $otherUser = $this->createUser('office_head');

        $this->actingAs($user)->put('/account/profile/'.$otherUser->id, [
            'name' => 'Tampered name',
            'email' => 'tampered@example.test',
        ])->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
            'name' => $otherUser->name,
            'email' => $otherUser->email,
            'role' => 'office_head',
        ]);
    }

    public function test_profile_photo_upload_replace_remove_and_invalid_content_validation(): void
    {
        Storage::fake('public');
        $user = $this->createUser('admin');
        $this->actingAs($user);
        $this->get(route('account.profile'))->assertOk()->assertSee('AT');

        $this->post(route('account.profile.photo'), [
            'photo' => UploadedFile::fake()->image('first.png'),
        ])->assertRedirect(route('account.profile'));

        $user->refresh();
        $firstPhoto = $user->profile_photo_path;
        $this->assertStringStartsWith('profile-photos/', $firstPhoto);
        Storage::disk('public')->assertExists($firstPhoto);
        $this->get(route('account.profile'))
            ->assertOk()
            ->assertSee('storage/'.$firstPhoto)
            ->assertSee('profile-image-avatar', false);
        URL::forceRootUrl('http://localhost/municipality/public');
        $this->assertSame('http://localhost/municipality/public/storage/'.$firstPhoto, url('storage/'.$firstPhoto));
        URL::forceRootUrl(null);

        $this->post(route('account.profile.photo'), [
            'photo' => UploadedFile::fake()->image('second.webp'),
        ])->assertRedirect(route('account.profile'));

        $user->refresh();
        $secondPhoto = $user->profile_photo_path;
        $this->assertNotSame($firstPhoto, $secondPhoto);
        Storage::disk('public')->assertMissing($firstPhoto);
        Storage::disk('public')->assertExists($secondPhoto);

        $this->delete(route('account.profile.photo.delete'))->assertRedirect(route('account.profile'));
        $user->refresh();
        $this->assertNull($user->profile_photo_path);
        Storage::disk('public')->assertMissing($secondPhoto);
        $this->get(route('account.profile'))->assertOk()->assertSee('AT')->assertDontSee('storage/profile-photos/');
        $user->update(['profile_photo_path' => 'profile-photos/missing.png']);
        $this->get(route('account.profile'))->assertOk()->assertSee('AT')->assertDontSee('missing.png');
        $user->update(['profile_photo_path' => null]);

        $this->post(route('account.profile.photo'), [
            'photo' => UploadedFile::fake()->create('payload.jpg', 10, 'application/x-php'),
        ])->assertSessionHasErrors('photo');

        $this->post(route('account.profile.photo'), [
            'photo' => UploadedFile::fake()->image('oversized.png')->size(2049),
        ])->assertSessionHasErrors('photo');
    }

    public function test_password_change_requires_current_password_and_hashes_new_password(): void
    {
        $user = $this->createUser('office_head', 'CurrentPassword123!');
        $this->actingAs($user);

        $this->put(route('account.profile.password'), [
            'current_password' => 'wrong password',
            'new_password' => 'NewSecurePassword123!',
            'new_password_confirmation' => 'NewSecurePassword123!',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('account.profile.password'), [
            'current_password' => 'CurrentPassword123!',
            'new_password' => 'NewSecurePassword123!',
            'new_password_confirmation' => 'NewSecurePassword123!',
        ])->assertRedirect(route('account.profile'));

        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->fresh()->password));
    }

    public function test_password_change_invalidates_other_database_sessions_and_rotates_remember_token(): void
    {
        $user = $this->createUser('office_head', 'CurrentPassword123!');
        $originalRememberToken = $user->remember_token;
        $this->actingAs($user);

        DB::table('sessions')->insert([
            [
                'id' => 'current-session',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'current',
                'payload' => 'current',
                'last_activity' => time(),
            ],
            [
                'id' => 'stolen-session',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'stolen',
                'payload' => 'stolen',
                'last_activity' => time(),
            ],
        ]);

        $this->withSession(['last_activity' => time()])
            ->put(route('account.profile.password'), [
                'current_password' => 'CurrentPassword123!',
                'new_password' => 'NewSecurePassword123!',
                'new_password_confirmation' => 'NewSecurePassword123!',
            ])
            ->assertRedirect(route('account.profile'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->password));
        $this->assertNotSame($originalRememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'stolen-session']);
    }

    public function test_privileged_user_can_generate_and_employee_cannot_generate_recovery_codes(): void
    {
        foreach (['admin', 'mayor', 'office_head'] as $role) {
            $user = $this->createUser($role);
            $this->actingAs($user)
                ->post(route('account.profile.mfa.recovery-codes'))
                ->assertRedirect(route('account.profile'))
                ->assertSessionHas('mfa_recovery_codes');

            $user->refresh();
            $this->assertIsArray($user->mfa_recovery_codes);
            $this->assertCount(10, $user->mfa_recovery_codes);
            $this->assertNotNull($user->mfa_recovery_codes_generated_at);
        }

        $employee = $this->createUser('employee');
        $this->actingAs($employee)
            ->post(route('account.profile.mfa.recovery-codes'))
            ->assertForbidden();
    }

    private function createUser(string $role, string $password = 'CurrentPassword123!'): User
    {
        return User::create([
            'name' => ucfirst($role).' Test User',
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createEmployee(User $user): Employee
    {
        $department = Department::create(['name' => 'Test Department']);

        return Employee::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => 'TEST-'.uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'employment_status' => 'Active',
        ]);
    }
}
