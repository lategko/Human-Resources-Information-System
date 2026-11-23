<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    private const FORMAT_VERSION = 1;

    private const FILE_MAGIC = 'CHRISBK1';

    private const CHUNK_SIZE = 65536;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function history(): array
    {
        $entries = [];

        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if (! Str::isUuid($id)) {
                continue;
            }

            try {
                $manifest = $this->readManifest($id);
                $backupPath = $this->backupPath($id);
                if (! is_file($backupPath) || filesize($backupPath) !== (int) $manifest['size_bytes']) {
                    $manifest['error'] = 'Encrypted backup file is missing or has an invalid size.';
                }
                $entries[] = $manifest;
            } catch (DecryptException|RuntimeException|\JsonException $exception) {
                Log::warning('A database backup manifest could not be authenticated.', [
                    'backup_id' => $id,
                    'exception' => $exception->getMessage(),
                ]);
                $entries[] = [
                    'id' => $id,
                    'error' => 'Backup metadata could not be authenticated or is malformed.',
                    'created_at' => null,
                ];
            }
        }

        usort($entries, fn (array $left, array $right) => strcmp(
            (string) ($right['created_at'] ?? ''),
            (string) ($left['created_at'] ?? '')
        ));

        return $entries;
    }

    public function create(string $source, ?User $actor = null): array
    {
        if (! in_array($source, ['manual', 'scheduled', 'pre_restore'], true)) {
            throw new RuntimeException('The requested backup source is not supported.');
        }

        return $this->withLock(fn () => $this->createUnlocked($source, $actor));
    }

    public function download(string $id): array
    {
        $manifest = $this->readManifest($id);
        $path = $this->backupPath($id);
        if (! is_file($path)) {
            throw new RuntimeException('The requested backup file is unavailable.');
        }

        return [
            'path' => $path,
            'filename' => 'camalaniugan-hris-'.Carbon::parse($manifest['created_at'])->format('Ymd-His').'.hrisbak',
        ];
    }

    public function restore(string $id, User $actor): array
    {
        return $this->withLock(fn () => $this->restoreUnlocked($id, $actor));
    }

    private function restoreUnlocked(string $id, User $actor): array
    {
        $restoreSql = $this->temporaryPath('restore-');
        $safetySql = null;
        $safetyBackup = null;

        try {
            $manifest = $this->decryptToSql($id, $restoreSql);
            $safetyBackup = $this->createUnlocked('pre_restore', $actor);

            try {
                DB::purge();
                $this->importSql($restoreSql);
            } catch (Throwable $restoreFailure) {
                try {
                    $safetySql = $this->temporaryPath('rollback-');
                    $this->decryptToSql($safetyBackup['id'], $safetySql);
                    DB::purge();
                    $this->importSql($safetySql);
                } catch (Throwable $rollbackFailure) {
                    Log::critical('Database restore and its automatic rollback both failed.', [
                        'backup_id' => $id,
                        'safety_backup_id' => $safetyBackup['id'],
                        'restore_error' => $restoreFailure->getMessage(),
                        'rollback_error' => $rollbackFailure->getMessage(),
                    ]);

                    throw new RuntimeException(
                        'Restore and automatic rollback both failed. Stop application use and initiate immediate database recovery.',
                        0,
                        $rollbackFailure
                    );
                }

                $this->auditLogger->record('database_backup.restore_failed_rolled_back', null, [
                    'backup_id' => $id,
                    'safety_backup_id' => $safetyBackup['id'],
                ]);

                throw new RuntimeException(
                    'Restore failed. The database was recovered from a new safety backup.',
                    0,
                    $restoreFailure
                );
            }

            $this->auditLogger->record('database_backup.restored', null, [
                'backup_id' => $id,
                'safety_backup_id' => $safetyBackup['id'],
                'sha256' => $manifest['plaintext_sha256'],
            ]);

            return [
                'backup' => $manifest,
                'safety_backup' => $safetyBackup,
            ];
        } finally {
            $this->removeTemporaryFile($restoreSql);
            if ($safetySql) {
                $this->removeTemporaryFile($safetySql);
            }
        }
    }

    private function createUnlocked(string $source, ?User $actor): array
    {
        $id = (string) Str::uuid();
        $sqlPath = $this->temporaryPath('dump-');
        $gzipPath = $this->temporaryPath('compressed-');
        $encryptedPath = $this->temporaryPath('encrypted-');
        $optionPath = null;
        $finalBackupPath = $this->backupPath($id);
        $finalManifestPath = $this->manifestPath($id);

        try {
            [$connection, $database] = $this->mysqlConnection();
            $optionPath = $this->createClientOptionsFile($connection);
            $this->dumpDatabase($connection, $database, $optionPath, $sqlPath);
            $plaintextHash = $this->compressSql($sqlPath, $gzipPath);
            $this->encryptCompressedFile($gzipPath, $encryptedPath);

            $manifest = [
                'id' => $id,
                'version' => self::FORMAT_VERSION,
                'source' => $source,
                'created_at' => now()->toIso8601String(),
                'created_by' => $actor?->id,
                'database' => $database,
                'filename' => $id.'.hrisbak',
                'size_bytes' => filesize($encryptedPath),
                'plaintext_size_bytes' => filesize($sqlPath),
                'ciphertext_sha256' => hash_file('sha256', $encryptedPath),
                'plaintext_sha256' => $plaintextHash,
            ];

            if (! rename($encryptedPath, $finalBackupPath)) {
                throw new RuntimeException('The encrypted database backup could not be saved.');
            }

            $this->writeEncryptedManifest($manifest, $finalManifestPath);
            $this->auditLogger->record('database_backup.created', null, [
                'backup_id' => $id,
                'source' => $source,
                'size_bytes' => $manifest['size_bytes'],
                'sha256' => $manifest['plaintext_sha256'],
            ]);

            return $manifest;
        } catch (Throwable $exception) {
            $this->removeTemporaryFile($finalBackupPath);
            $this->removeTemporaryFile($finalManifestPath);
            Log::error('Database backup creation failed.', [
                'backup_id' => $id,
                'source' => $source,
                'exception' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            $this->removeTemporaryFile($sqlPath);
            $this->removeTemporaryFile($gzipPath);
            $this->removeTemporaryFile($encryptedPath);
            if ($optionPath) {
                $this->removeTemporaryFile($optionPath);
            }
        }
    }

    private function decryptToSql(string $id, string $sqlPath): array
    {
        $manifest = $this->readManifest($id);
        [, $activeDatabase] = $this->mysqlConnection();
        if (($manifest['database'] ?? null) !== $activeDatabase) {
            throw new RuntimeException(
                'The backup belongs to a different MySQL database and cannot be restored into this environment.'
            );
        }
        $encryptedPath = $this->backupPath($id);
        if (! is_file($encryptedPath)
            || filesize($encryptedPath) !== (int) $manifest['size_bytes']
            || ! hash_equals($manifest['ciphertext_sha256'], hash_file('sha256', $encryptedPath))) {
            throw new RuntimeException('The backup file failed its integrity check.');
        }

        $gzipPath = $this->temporaryPath('verified-');
        try {
            $this->decryptFileToCompressed($encryptedPath, $gzipPath);
            $plaintextHash = $this->decompressAndHash($gzipPath, $sqlPath);
            if (! hash_equals($manifest['plaintext_sha256'], $plaintextHash)
                || filesize($sqlPath) !== (int) $manifest['plaintext_size_bytes']) {
                throw new RuntimeException('The decrypted SQL dump failed its integrity check.');
            }

            return $manifest;
        } finally {
            $this->removeTemporaryFile($gzipPath);
        }
    }

    private function mysqlConnection(): array
    {
        $connectionName = (string) config('database.default');
        $connection = config('database.connections.'.$connectionName);
        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Database backup and restore require an active MySQL connection.');
        }
        $database = $connection['database'] ?? null;
        if (! is_string($database) || $database === '') {
            throw new RuntimeException('The active MySQL database name is not configured.');
        }

        return [$connection, $database];
    }

    private function createClientOptionsFile(array $connection): string
    {
        $path = $this->temporaryPath('mysql-');
        $username = $this->quoteOptionValue((string) ($connection['username'] ?? ''));
        $password = $this->quoteOptionValue((string) ($connection['password'] ?? ''));
        $content = "[client]\nuser={$username}\npassword={$password}\n";

        if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
            $this->removeTemporaryFile($path);
            throw new RuntimeException('Temporary MySQL client credentials could not be written.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
            $this->removeTemporaryFile($path);
            throw new RuntimeException('Temporary MySQL client credentials could not be protected.');
        }

        return $path;
    }

    private function quoteOptionValue(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $value
        ).'"';
    }

    private function dumpDatabase(array $connection, string $database, string $optionPath, string $sqlPath): void
    {
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? 3306);
        $process = new Process([
            (string) config('hris_backup.mysqldump_binary', 'mysqldump'),
            '--defaults-extra-file='.$optionPath,
            '--host='.$host,
            '--port='.$port,
            '--protocol=tcp',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$sqlPath,
            $database,
        ]);
        $process->setTimeout((int) config('hris_backup.process_timeout', 3600));
        $process->run();
        if (! $process->isSuccessful() || ! is_file($sqlPath) || filesize($sqlPath) === 0) {
            Log::error('MySQL database dump failed.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
            throw new RuntimeException('Database backup failed. Verify the MySQL client configuration and server logs.');
        }
    }

    private function importSql(string $sqlPath): void
    {
        [$connection, $database] = $this->mysqlConnection();
        $optionPath = $this->createClientOptionsFile($connection);
        $input = fopen($sqlPath, 'rb');
        if ($input === false) {
            $this->removeTemporaryFile($optionPath);
            throw new RuntimeException('The verified SQL dump could not be opened for restoration.');
        }

        try {
            $process = new Process([
                (string) config('hris_backup.mysql_binary', 'mysql'),
                '--defaults-extra-file='.$optionPath,
                '--host='.(string) ($connection['host'] ?? '127.0.0.1'),
                '--port='.(string) ($connection['port'] ?? 3306),
                '--protocol=tcp',
                '--default-character-set=utf8mb4',
                '--database='.$database,
            ]);
            $process->setInput($input);
            $process->setTimeout((int) config('hris_backup.process_timeout', 3600));
            $process->run();
            if (! $process->isSuccessful()) {
                Log::error('MySQL database restore import failed.', [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                ]);
                throw new RuntimeException('Database restore failed. Automatic recovery will be attempted.');
            }
        } finally {
            fclose($input);
            $this->removeTemporaryFile($optionPath);
        }
    }

    private function compressSql(string $sqlPath, string $gzipPath): string
    {
        $input = fopen($sqlPath, 'rb');
        $output = gzopen($gzipPath, 'wb6');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            throw new RuntimeException('The SQL dump could not be compressed.');
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('The SQL dump could not be read for compression.');
                }
                if ($chunk === '') {
                    if (! feof($input)) {
                        throw new RuntimeException('The SQL dump stream stopped before reaching its end.');
                    }
                    break;
                }
                hash_update($hash, $chunk);
                if (gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('The SQL dump could not be fully compressed.');
                }
            }
        } finally {
            fclose($input);
            gzclose($output);
        }

        return hash_final($hash);
    }

    private function encryptCompressedFile(string $gzipPath, string $encryptedPath): void
    {
        $input = fopen($gzipPath, 'rb');
        $output = fopen($encryptedPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('The compressed SQL dump could not be encrypted.');
        }

        try {
            $this->writeAll($output, self::FILE_MAGIC);
            $index = 0;
            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new RuntimeException('The compressed SQL dump could not be read for encryption.');
                }
                if ($chunk === '') {
                    if (! feof($input)) {
                        throw new RuntimeException('The compressed backup stream stopped unexpectedly.');
                    }
                    break;
                }

                $ciphertext = Crypt::encryptString(pack('N', $index).$chunk);
                $this->writeAll($output, pack('N', strlen($ciphertext)).$ciphertext);
                $index++;
            }
            if ($index === 0) {
                throw new RuntimeException('The compressed SQL dump is empty.');
            }
            $this->writeAll($output, pack('N', 0));
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function decryptFileToCompressed(string $encryptedPath, string $gzipPath): void
    {
        $input = fopen($encryptedPath, 'rb');
        $output = fopen($gzipPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('The encrypted backup could not be opened for verification.');
        }

        try {
            if ($this->readExact($input, strlen(self::FILE_MAGIC)) !== self::FILE_MAGIC) {
                throw new RuntimeException('The backup format is not supported.');
            }

            $expectedIndex = 0;
            while (true) {
                $lengthBytes = $this->readExact($input, 4);
                $length = unpack('Nlength', $lengthBytes)['length'];
                if ($length === 0) {
                    break;
                }
                if ($length > self::CHUNK_SIZE * 3) {
                    throw new RuntimeException('The encrypted backup contains an invalid chunk length.');
                }

                $ciphertext = $this->readExact($input, $length);
                $payload = Crypt::decryptString($ciphertext);
                if (strlen($payload) <= 4
                    || unpack('Nindex', substr($payload, 0, 4))['index'] !== $expectedIndex) {
                    throw new RuntimeException('The encrypted backup chunk sequence is invalid.');
                }
                $compressedChunk = substr($payload, 4);
                $this->writeAll($output, $compressedChunk);
                $expectedIndex++;
            }

            if ($expectedIndex === 0 || fread($input, 1) !== '') {
                throw new RuntimeException('The encrypted backup is empty or contains unexpected trailing data.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function decompressAndHash(string $gzipPath, string $sqlPath): string
    {
        $input = gzopen($gzipPath, 'rb');
        $output = fopen($sqlPath, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('The verified backup could not be expanded as SQL.');
        }

        $hash = hash_init('sha256');
        $bytesWritten = 0;
        try {
            while (! gzeof($input)) {
                $chunk = gzread($input, self::CHUNK_SIZE);
                if ($chunk === false || ($chunk === '' && ! gzeof($input))) {
                    throw new RuntimeException('The backup compression stream is invalid.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->writeAll($output, $chunk);
                hash_update($hash, $chunk);
                $bytesWritten += strlen($chunk);
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
        if ($bytesWritten === 0) {
            throw new RuntimeException('The verified backup contains no SQL data.');
        }

        return hash_final($hash);
    }

    private function writeEncryptedManifest(array $manifest, string $path): void
    {
        $encrypted = Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR));
        $temporaryPath = $this->temporaryPath('manifest-');
        try {
            if (file_put_contents($temporaryPath, $encrypted, LOCK_EX) !== strlen($encrypted)
                || ! rename($temporaryPath, $path)) {
                throw new RuntimeException('Backup metadata could not be saved.');
            }
        } finally {
            $this->removeTemporaryFile($temporaryPath);
        }
    }

    private function readManifest(string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('The backup identifier is invalid.');
        }
        $path = $this->manifestPath($id);
        if (! is_file($path)) {
            throw new RuntimeException('The requested backup metadata is unavailable.');
        }

        $encrypted = file_get_contents($path);
        if (! is_string($encrypted)) {
            throw new RuntimeException('The requested backup metadata could not be read.');
        }
        $manifest = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ($manifest['id'] ?? null) !== $id
            || ($manifest['version'] ?? null) !== self::FORMAT_VERSION
            || ($manifest['filename'] ?? null) !== $id.'.hrisbak'
            || ! isset($manifest['created_at'], $manifest['size_bytes'], $manifest['ciphertext_sha256'], $manifest['plaintext_sha256'])) {
            throw new RuntimeException('The requested backup metadata is invalid.');
        }

        return $manifest;
    }

    private function readExact($stream, int $length): string
    {
        $value = '';
        while (strlen($value) < $length && ! feof($stream)) {
            $chunk = fread($stream, $length - strlen($value));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $value .= $chunk;
        }
        if (strlen($value) !== $length) {
            throw new RuntimeException('The encrypted backup is truncated.');
        }

        return $value;
    }

    private function writeAll($stream, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($stream, substr($value, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('A protected backup file could not be written completely.');
            }
            $offset += $written;
        }
    }

    private function withLock(callable $callback): mixed
    {
        $handle = fopen($this->directory().DIRECTORY_SEPARATOR.'.operation.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('The backup operation lock could not be opened.');
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another backup or restore operation is already in progress.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function directory(): string
    {
        $relativeDirectory = trim((string) config('hris_backup.directory', 'hris-backups'), '/\\');
        if ($relativeDirectory === ''
            || preg_match('~\A[A-Za-z0-9_-]+(?:[\\\\/][A-Za-z0-9_-]+)*\z~', $relativeDirectory) !== 1) {
            throw new RuntimeException('The database backup directory must be a protected relative path.');
        }

        $privateRoot = storage_path('app/private');
        if (is_link($privateRoot)) {
            throw new RuntimeException('The private application storage directory cannot be a symbolic link.');
        }
        if (! is_dir($privateRoot) && ! mkdir($privateRoot, 0700, true) && ! is_dir($privateRoot)) {
            throw new RuntimeException('The private application storage directory could not be created.');
        }
        $root = realpath($privateRoot);
        $directory = $privateRoot.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The private database backup directory could not be created.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($directory, 0700)) {
            throw new RuntimeException('The private database backup directory permissions could not be restricted.');
        }
        $resolvedDirectory = realpath($directory);
        if ($root === false || $resolvedDirectory === false
            || ! str_starts_with(
                strtolower($resolvedDirectory.DIRECTORY_SEPARATOR),
                strtolower($root.DIRECTORY_SEPARATOR)
            )) {
            throw new RuntimeException('The database backup directory must remain inside private application storage.');
        }

        return $resolvedDirectory;
    }

    private function temporaryPath(string $prefix): string
    {
        $path = tempnam($this->directory(), $prefix);
        if ($path === false) {
            throw new RuntimeException('A temporary protected backup file could not be created.');
        }
        if (DIRECTORY_SEPARATOR !== '\\' && ! chmod($path, 0600)) {
            $this->removeTemporaryFile($path);
            throw new RuntimeException('A temporary protected backup file could not be secured.');
        }

        return $path;
    }

    private function backupPath(string $id): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$id.'.hrisbak';
    }

    private function manifestPath(string $id): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$id.'.json';
    }

    private function removeTemporaryFile(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            Log::warning('A temporary database backup file could not be removed.', ['path' => $path]);
        }
    }
}
