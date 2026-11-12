<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountPasswordRecoveryCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('session.driver', 'database');
        Config::set('session.connection', null);
    }

    public function test_command_resets_an_existing_account_password_invalidates_sessions_and_writes_an_audit_event(): void
    {
        $user = User::factory()->create([
            'name' => 'Authorized Super Admin',
            'email' => 'super-admin@example.test',
            'role' => 'super_admin',
            'password' => 'OriginalPassword123!',
            'is_active' => true,
        ]);
        $rememberToken = $user->remember_token;

        DB::table('sessions')->insert([
            [
                'id' => 'target-session',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => 'target-session-payload',
                'last_activity' => time(),
            ],
            [
                'id' => 'other-session',
                'user_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => 'other-session-payload',
                'last_activity' => time(),
            ],
        ]);

        $newPassword = 'RecoveredPassword123!';

        $this->artisan('hris:account:reset-password')
            ->expectsQuestion('Account email', $user->email)
            ->expectsConfirmation('Continue with this account?', 'yes')
            ->expectsQuestion('New password (input is hidden)', $newPassword)
            ->expectsQuestion('Confirm new password (input is hidden)', $newPassword)
            ->expectsOutputToContain('Password reset successfully')
            ->doesntExpectOutput($newPassword)
            ->assertSuccessful();

        $user = $user->fresh();
        $this->assertSame('Authorized Super Admin', $user->name);
        $this->assertSame('super-admin@example.test', $user->email);
        $this->assertSame('super_admin', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame($rememberToken, $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);

        $event = AuditLog::where('action', 'user.credentials_reset')->firstOrFail();
        $this->assertNull($event->user_id);
        $this->assertSame($user->id, $event->subject_id);
        $this->assertSame('artisan account recovery', $event->metadata['source']);
        $this->assertStringNotContainsString($newPassword, json_encode($event->metadata));
    }

    public function test_command_rejects_an_unknown_account_without_creating_a_user(): void
    {
        $this->artisan('hris:account:reset-password')
            ->expectsQuestion('Account email', 'missing@example.test')
            ->expectsOutputToContain('No existing account matches that email')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_password_confirmation_mismatch_without_changing_the_account(): void
    {
        $user = User::factory()->create(['email' => 'hrmo@example.test']);
        $originalHash = $user->password;
        $newPassword = 'RecoveredPassword123!';

        $this->artisan('hris:account:reset-password')
            ->expectsQuestion('Account email', $user->email)
            ->expectsConfirmation('Continue with this account?', 'yes')
            ->expectsQuestion('New password (input is hidden)', $newPassword)
            ->expectsQuestion('Confirm new password (input is hidden)', 'DifferentPassword123!')
            ->expectsOutputToContain('Password confirmation does not match')
            ->doesntExpectOutput($newPassword)
            ->assertFailed();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_command_rejects_passwords_shorter_than_twelve_characters(): void
    {
        $user = User::factory()->create(['email' => 'hrmo@example.test']);
        $originalHash = $user->password;

        $this->artisan('hris:account:reset-password')
            ->expectsQuestion('Account email', $user->email)
            ->expectsConfirmation('Continue with this account?', 'yes')
            ->expectsQuestion('New password (input is hidden)', 'TooShort123')
            ->expectsQuestion('Confirm new password (input is hidden)', 'TooShort123')
            ->expectsOutputToContain('at least 12 characters')
            ->assertFailed();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_command_leaves_the_account_unchanged_when_the_operator_cancels(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);
        $originalHash = $user->password;

        $this->artisan('hris:account:reset-password')
            ->expectsQuestion('Account email', $user->email)
            ->expectsConfirmation('Continue with this account?', 'no')
            ->expectsOutputToContain('Password reset cancelled')
            ->assertSuccessful();

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
