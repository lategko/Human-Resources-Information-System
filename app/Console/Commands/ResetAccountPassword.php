<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ResetAccountPassword extends Command
{
    protected $signature = 'hris:account:reset-password';

    protected $description = 'Interactively reset an existing HRIS account password.';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this recovery command in an interactive terminal.');

            return self::FAILURE;
        }

        $email = trim((string) $this->ask('Account email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No existing account matches that email. No account was created.');

            return self::FAILURE;
        }

        $connectionName = config('database.default');
        $userConnectionName = $user->getConnectionName() ?: $connectionName;
        $sessionConnectionName = config('session.connection') ?: $connectionName;
        $sessionTable = config('session.table');

        if (config('session.driver') !== 'database'
            || $userConnectionName !== $sessionConnectionName
            || ! Schema::connection($sessionConnectionName)->hasTable($sessionTable)
            || ! Schema::connection($sessionConnectionName)->hasColumn($sessionTable, 'user_id')) {
            $this->error('Account recovery requires a database session table on the same connection as the user account.');

            return self::FAILURE;
        }

        if (! Schema::connection($connectionName)->hasTable('audit_logs')) {
            $this->error('The audit log table is unavailable. No account changes were made.');

            return self::FAILURE;
        }

        $status = $user->is_active ? 'active' : 'inactive';
        $this->line("Target account: {$user->email} · {$user->role} · {$status}");

        if (! $this->confirm('Continue with this account?', false)) {
            $this->info('Password reset cancelled. No account changes were made.');

            return self::SUCCESS;
        }

        $password = $this->secret('New password (input is hidden)', false);
        $passwordConfirmation = $this->secret('Confirm new password (input is hidden)', false);

        if (! is_string($password) || mb_strlen($password) < 12) {
            $this->error('The new password must be at least 12 characters. No account changes were made.');

            return self::FAILURE;
        }

        if (! is_string($passwordConfirmation) || ! hash_equals($password, $passwordConfirmation)) {
            $this->error('Password confirmation does not match. No account changes were made.');

            return self::FAILURE;
        }

        $updated = DB::connection($connectionName)->transaction(function () use ($user, $email, $password, $sessionConnectionName, $sessionTable): bool {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser || $lockedUser->email !== $email) {
                return false;
            }

            $lockedUser->password = $password;
            $lockedUser->setRememberToken(Str::random(60));
            $lockedUser->saveOrFail();

            DB::connection($sessionConnectionName)
                ->table($sessionTable)
                ->where('user_id', $lockedUser->getKey())
                ->delete();

            AuditLog::create([
                'user_id' => null,
                'action' => 'user.credentials_reset',
                'subject_type' => User::class,
                'subject_id' => $lockedUser->getKey(),
                'metadata' => [
                    'source' => 'artisan account recovery',
                    'operator' => getenv(PHP_OS_FAMILY === 'Windows' ? 'USERNAME' : 'USER') ?: 'unknown',
                ],
                'ip_address' => null,
            ]);

            return true;
        });

        if (! $updated) {
            $this->error('The target account changed during recovery. No account changes were made.');
            return self::FAILURE;
        }

        $this->info("Password reset successfully for {$email}. Existing database sessions were invalidated.");

        if (! $user->is_active) {
            $this->warn('This account remains inactive and cannot sign in until an authorized administrator activates it.');
        }

        return self::SUCCESS;
    }
}
