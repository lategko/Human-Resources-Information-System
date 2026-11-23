<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupController extends Controller
{
    public function store(Request $request, DatabaseBackupService $backupService): RedirectResponse
    {
        $backupService->create('manual', $request->user());

        return back()->with('success', 'Encrypted database backup created successfully.');
    }

    public function download(
        string $backup,
        DatabaseBackupService $backupService,
        AuditLogger $auditLogger
    ): StreamedResponse {
        $download = $backupService->download($backup);
        $auditLogger->record('database_backup.downloaded', null, ['backup_id' => $backup]);
        $path = $download['path'];

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('The encrypted backup could not be opened for download.');
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $download['filename'], [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restore(
        Request $request,
        string $backup,
        DatabaseBackupService $backupService
    ): RedirectResponse {
        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', 'string', Rule::in(['RESTORE'])],
        ]);

        set_time_limit(0);
        Artisan::call('down', ['--retry' => 60]);

        try {
            $backupService->restore($backup, $request->user());
        } finally {
            Artisan::call('up');
        }

        return back()->with(
            'success',
            'Database restored successfully. A pre-restore safety backup was also created.'
        );
    }
}
