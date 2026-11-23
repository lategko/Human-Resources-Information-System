<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedConfirmationModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_render_the_shared_dialog_and_confirm_department_changes(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('departments.index'))
            ->assertOk()
            ->assertSee('<dialog', false)
            ->assertSee('data-hris-modal', false)
            ->assertSee('id="hris-toast-region"', false)
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
}
