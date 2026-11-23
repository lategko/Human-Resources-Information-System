<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseBackupMySqlIntegrationTest extends TestCase
{
    public function test_mysql_backup_restores_data_in_a_new_isolated_database(): void
    {
        if (getenv('HRIS_RUN_MYSQL_BACKUP_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped('Set HRIS_RUN_MYSQL_BACKUP_INTEGRATION_TESTS=1 to run the isolated MySQL restore test.');
        }

        $originalDefault = config('database.default');
        $originalConnection = config('database.connections.mysql');
        $adminConnection = [...$originalConnection, 'database' => 'mysql'];
        $targetDatabase = 'hris_backup_test_'.Str::lower(Str::random(12));
        $created = false;
        $backupDirectory = storage_path('app/private/'.config('hris_backup.directory', 'hris-backups'));
        $existingManifestPaths = array_fill_keys(glob($backupDirectory.DIRECTORY_SEPARATOR.'*.json') ?: [], true);

        try {
            config(['database.connections.backup_admin' => $adminConnection]);
            $exists = DB::connection('backup_admin')->selectOne(
                'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ?',
                [$targetDatabase]
            );
            $this->assertNull($exists, 'The isolated database name must not already exist.');
            $this->assertNotSame('mysql', $targetDatabase);

            DB::connection('backup_admin')->statement(
                "CREATE DATABASE `{$targetDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
            $created = true;

            config(['database.connections.mysql.database' => $targetDatabase]);
            config(['database.default' => 'mysql']);
            DB::purge('mysql');

            Schema::connection('mysql')->create('backup_probe', function ($table) {
                $table->id();
                $table->string('state');
            });
            Schema::connection('mysql')->create('audit_logs', function ($table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('action');
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamps();
            });
            DB::connection('mysql')->table('backup_probe')->insert(['state' => 'before restore']);

            $actor = new User;
            $actor->forceFill(['id' => 1]);
            $backupService = app(DatabaseBackupService::class);
            $backup = $backupService->create('manual');
            DB::connection('mysql')->table('backup_probe')->update(['state' => 'after mutation']);

            $backupPath = $backupDirectory.DIRECTORY_SEPARATOR.$backup['id'].'.hrisbak';
            $originalBackupFile = file_get_contents($backupPath);
            $this->assertIsString($originalBackupFile);
            file_put_contents($backupPath, substr_replace(
                $originalBackupFile,
                chr(ord($originalBackupFile[10]) ^ 1),
                10,
                1
            ));
            try {
                $backupService->restore($backup['id'], $actor);
                $this->fail('A modified backup file must be rejected before restoration.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('The backup file failed its integrity check.', $exception->getMessage());
            } finally {
                file_put_contents($backupPath, $originalBackupFile);
            }
            $this->assertSame('after mutation', DB::connection('mysql')->table('backup_probe')->value('state'));

            $restoration = $backupService->restore($backup['id'], $actor);

            $this->assertSame(
                'before restore',
                DB::connection('mysql')->table('backup_probe')->value('state')
            );
            $this->assertNotSame($backup['id'], $restoration['safety_backup']['id']);
            $this->assertSame(
                1,
                DB::connection('mysql')->table('audit_logs')->where('action', 'database_backup.restored')->count()
            );
        } finally {
            DB::purge('mysql');
            config(['database.connections.mysql' => $originalConnection]);
            config(['database.default' => $originalDefault]);
            DB::purge('backup_admin');
            if ($created) {
                DB::connection('backup_admin')->statement("DROP DATABASE `{$targetDatabase}`");
            }

            foreach (glob($backupDirectory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $manifestPath) {
                if (! isset($existingManifestPaths[$manifestPath])) {
                    $id = basename($manifestPath, '.json');
                    @unlink($manifestPath);
                    @unlink($backupDirectory.DIRECTORY_SEPARATOR.$id.'.hrisbak');
                }
            }
        }
    }
}
