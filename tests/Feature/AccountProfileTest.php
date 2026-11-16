<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
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
        foreach (['admin', 'office_head', 'mayor'] as $role) {
            $user = $this->createUser($role);

            if ($role === 'admin') {
                $user->update(['can_final_approve' => true]);
            }

            $this->actingAs($user)->put(route('account.profile.update'), [
                'name' => 'Updated '.ucfirst($role),
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
                'can_final_approve' => $role === 'admin',
            ]);
        }
    }

    public function test_linked_employee_can_update_own_personal_details_without_changing_permissions(): void
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
            'email' => 'updated@example.test',
            'role' => 'admin',
            'is_active' => false,
            'can_final_approve' => true,
        ])->assertRedirect(route('account.profile'));

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'first_name' => 'Updated',
            'middle_name' => 'Maria',
            'last_name' => 'Employee',
            'contact_number' => '09171234567',
            'address' => 'New residential address',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Maria Employee Jr.',
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
            'email' => 'updated-admin@example.test',
            'role' => 'admin',
        ])->assertRedirect(route('account.profile'));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Human Resources Officer',
            'email' => 'updated-admin@example.test',
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
