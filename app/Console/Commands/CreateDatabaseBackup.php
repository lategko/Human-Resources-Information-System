<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class CreateDatabaseBackup extends Command
{
    protected $signature = 'hris:backup {--source=scheduled}';

    protected $description = 'Create an encrypted private backup of the HRIS MySQL database';

    public function handle(DatabaseBackupService $backupService): int
    {
        try {
            $manifest = $backupService->create((string) $this->option('source'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Encrypted database backup created: '.$manifest['id']);

        return self::SUCCESS;
    }
}
