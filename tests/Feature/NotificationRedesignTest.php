<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_dropdown_uses_real_user_notifications_and_mark_actions(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);
        $otherUser = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'account_setup_completed' => true,
        ]);

        $notification = $this->createNotification($user, 'A leave application is ready for HRMO verification.');
        $otherNotification = $this->createNotification($otherUser, 'Another user notification.');

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('id="notification-panel"', false);
        $response->assertSee('Mark all as read');
        $response->assertSee('A leave application is ready for HRMO verification.');
        $response->assertDontSee('Another user notification.');

        $this->postJson(route('notifications.read', $notification->id))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'unread_count' => 0,
            ]);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $second = $this->createNotification($user, 'A second unread notification.');
        $this->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'unread_count' => 0,
            ]);

        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_a_user_cannot_read_another_users_notification(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $otherUser = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $otherNotification = $this->createNotification($otherUser, 'Private notification.');

        $this->actingAs($user)
            ->post(route('notifications.read', $otherNotification->id))
            ->assertNotFound();

        $this->assertNull($otherNotification->fresh()->read_at);
    }

    private function createNotification(User $user, string $message): \Illuminate\Notifications\DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => LeaveApplicationUpdated::class,
            'data' => [
                'message' => $message,
                'status' => 'Under HRMO Verification',
            ],
        ]);
    }
}
