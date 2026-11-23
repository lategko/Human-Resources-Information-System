<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class DatabaseBackupAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_backup_history_and_start_a_manual_backup(): void
    {
        $admin = $this->makeUser('admin', 'backup-admin');
        $backupService = Mockery::mock(DatabaseBackupService::class);
        $backupService->shouldReceive('history')->once()->andReturn([[
            'id' => 'e7f2341a-ae19-4b6a-84c0-71f4a8f57120',
            'created_at' => '2026-11-22T13:00:00+08:00',
            'source' => 'manual',
            'size_bytes' => 1024,
        ]]);
        $backupService->shouldReceive('create')
            ->once()
            ->with('manual', Mockery::on(fn (User $actor) => $actor->is($admin)))
            ->andReturn(['id' => 'e7f2341a-ae19-4b6a-84c0-71f4a8f57120']);
        $this->app->instance(DatabaseBackupService::class, $backupService);

        $this->actingAs($admin)
            ->get(route('settings.system'))
            ->assertOk()
            ->assertSee('Database backup and restore')
            ->assertSee('Create manual backup')
            ->assertSee('Download encrypted file')
            ->assertSee('Verify and restore');

        $this->actingAs($admin)
            ->post(route('settings.system.backups.store'))
            ->assertRedirect()
            ->assertSessionHas('success', 'Encrypted database backup created successfully.');
    }

    public function test_admin_can_download_an_encrypted_backup_through_the_authorized_route(): void
    {
        $admin = $this->makeUser('admin', 'backup-download-admin');
        $backupId = 'e7f2341a-ae19-4b6a-84c0-71f4a8f57120';
        $path = tempnam(sys_get_temp_dir(), 'hris-backup-download-');
        file_put_contents($path, 'encrypted-test-payload');
        $backupService = Mockery::mock(DatabaseBackupService::class);
        $backupService->shouldReceive('download')->once()->with($backupId)->andReturn([
            'path' => $path,
            'filename' => 'camalaniugan-hris-20261122-130000.hrisbak',
        ]);
        $this->app->instance(DatabaseBackupService::class, $backupService);

        try {
            $this->actingAs($admin)
                ->get(route('settings.system.backups.download', $backupId))
                ->assertDownload('camalaniugan-hris-20261122-130000.hrisbak');
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'database_backup.downloaded',
            ]);
        } finally {
            unlink($path);
        }
    }

    public function test_only_admin_can_access_backup_settings_and_actions(): void
    {
        $roles = [
            $this->makeUser('office_head', 'backup-head'),
            $this->makeUser('employee', 'backup-employee'),
            $this->makeUser('mayor', 'backup-mayor'),
        ];
        $backupId = 'e7f2341a-ae19-4b6a-84c0-71f4a8f57120';

        foreach ($roles as $user) {
            $this->actingAs($user)->get(route('settings.system'))->assertForbidden();
            $this->actingAs($user)->post(route('settings.system.backups.store'))->assertForbidden();
            $this->actingAs($user)->get(route('settings.system.backups.download', $backupId))->assertForbidden();
            $this->actingAs($user)->post(route('settings.system.backups.restore', $backupId))->assertForbidden();
        }
    }

    public function test_restore_requires_current_password_and_exact_confirmation(): void
    {
        $admin = $this->makeUser('admin', 'backup-restore-admin');
        $backupId = 'e7f2341a-ae19-4b6a-84c0-71f4a8f57120';
        $backupService = Mockery::mock(DatabaseBackupService::class);
        $backupService->shouldNotReceive('restore');
        $this->app->instance(DatabaseBackupService::class, $backupService);

        $this->actingAs($admin)->post(route('settings.system.backups.restore', $backupId), [
            'password' => 'wrong-password',
            'confirmation' => 'RESTORE',
        ])->assertSessionHasErrors('password');

        $this->actingAs($admin)->post(route('settings.system.backups.restore', $backupId), [
            'password' => 'TestAccount123!',
            'confirmation' => 'restore',
        ])->assertSessionHasErrors('confirmation');

        Artisan::shouldReceive('call')->twice()->andReturn(0);
        $backupService->shouldReceive('restore')
            ->once()
            ->with($backupId, Mockery::on(fn (User $actor) => $actor->is($admin)))
            ->andReturn([]);

        $this->actingAs($admin)->post(route('settings.system.backups.restore', $backupId), [
            'password' => 'TestAccount123!',
            'confirmation' => 'RESTORE',
        ])->assertRedirect()->assertSessionHas('success');
    }

    private function makeUser(string $role, string $prefix): User
    {
        return User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'@example.test',
            'password' => 'TestAccount123!',
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
