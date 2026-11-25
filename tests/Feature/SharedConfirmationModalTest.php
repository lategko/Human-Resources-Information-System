<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedConfirmationModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_render_sweetalert_confirmation_hooks_for_department_changes(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertDontSee('<dialog', false)
            ->assertDontSee('data-hris-modal', false)
            ->assertSee('data-confirm-title="Add Department?"', false)
            ->assertSee('data-confirm-title="Save Department Changes?"', false);
    }

    public function test_password_recovery_pages_use_the_shared_flash_notice(): void
    {
        $this->withSession(['status' => 'Password link sent.'])
            ->get(route('password.request'))
            ->assertOk()
            ->assertSee('data-hris-flash', false)
            ->assertSee('Password link sent.');
    }

    public function test_temporary_password_setup_uses_the_shared_warning_confirmation(): void
    {
        $user = User::factory()->create([
            'role' => 'employee',
            'is_active' => true,
            'must_change_password' => true,
            'account_setup_completed' => false,
        ]);

        $response = $this->actingAs($user)
            ->get(route('account.setup'))
            ->assertOk()
            ->assertSee('data-confirm-type="warning"', false)
            ->assertSee('data-confirm-title="Change Password and Continue?"', false)
            ->assertSee('data-confirm-text="Change Password"', false)
            ->assertSee('You are currently using a temporary password.', false)
            ->assertSee('Would you like to proceed?', false)
            ->assertDontSee('<dialog', false);

        $this->assertStringNotContainsString('data-hris-modal', $response->getContent());
    }
}
