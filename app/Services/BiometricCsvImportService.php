<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BiometricImport;
use App\Models\BiometricImportRecord;
use App\Models\Employee;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BiometricCsvImportService
{
    public function import(UploadedFile $file, int $userId): BiometricImport
    {
        $import = BiometricImport::create([
            'uploaded_by' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            $import->update(['status' => 'failed', 'error_count' => 1]);
            throw new RuntimeException('The uploaded CSV file could not be opened.');
        }

        $header = fgetcsv($stream);
        if (! is_array($header)) {
            fclose($stream);
            $import->update(['status' => 'failed', 'error_count' => 1]);
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'The uploaded CSV file is empty.']);
        }
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $header);
        foreach (['biometric_id', 'date'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($stream);
                $import->update(['status' => 'failed', 'error_count' => 1]);
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => 'CSV header is missing the required "'.$required.'" column.',
                ]);
            }
        }

        $seen = [];
        $rowNumber = 1;
        $imported = 0;
        $errors = 0;

        try {
            DB::transaction(function () use ($stream, $header, $import, &$seen, &$rowNumber, &$imported, &$errors) {
                while (($values = fgetcsv($stream)) !== false) {
                    $rowNumber++;
                    if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                        continue;
                    }
                    $raw = [];
                    foreach ($header as $index => $column) {
                        $raw[$column] = trim((string) ($values[$index] ?? ''));
                    }
                    $employee = Employee::where('biometric_id', $raw['biometric_id'] ?? '')->first();
                    $reason = $employee ? null : 'No employee is mapped to this biometric ID.';
                    $date = $this->validDate($raw['date'] ?? '');
                    if (! $date) {
                        $reason ??= 'Date must use YYYY-MM-DD format.';
                    }
                    $key = ($raw['biometric_id'] ?? '').'|'.($raw['date'] ?? '');
                    if (isset($seen[$key])) {
                        $reason ??= 'Duplicate biometric ID and date in this file.';
                    }
                    $seen[$key] = true;
                    if ($reason === null && AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $date)->exists()) {
                        $reason = 'Attendance for this employee and date already exists.';
                    }
                    $timeIn = $this->validTime($raw['time_in'] ?? '');
                    $timeOut = $this->validTime($raw['time_out'] ?? '');
                    if (($raw['time_in'] ?? '') !== '' && $timeIn === null) {
                        $reason ??= 'Time in must use HH:MM or HH:MM:SS format.';
                    }
                    if (($raw['time_out'] ?? '') !== '' && $timeOut === null) {
                        $reason ??= 'Time out must use HH:MM or HH:MM:SS format.';
                    }

                    if ($reason === null) {
                        $hours = $timeIn && $timeOut
                            ? round(max(0, Carbon::parse($timeIn)->diffInMinutes(Carbon::parse($timeOut), false)) / 60, 2)
                            : null;
                        AttendanceRecord::create([
                            'employee_id' => $employee->id,
                            'work_date' => $date,
                            'time_in' => $timeIn,
                            'time_out' => $timeOut,
                            'total_working_hours' => $hours,
                            'status' => 'Present',
                            'source' => 'biometric_import',
                        ]);
                        $imported++;
                    } else {
                        $errors++;
                    }
                    BiometricImportRecord::create([
                        'biometric_import_id' => $import->id,
                        'employee_id' => $employee?->id,
                        'row_number' => $rowNumber,
                        'raw_data' => $raw,
                        'status' => $reason ? 'error' : 'imported',
                        'error_message' => $reason,
                    ]);
                }

                $import->update([
                    'status' => $errors ? ($imported ? 'completed_with_errors' : 'failed') : 'completed',
                    'row_count' => $imported + $errors,
                    'imported_count' => $imported,
                    'error_count' => $errors,
                ]);
            });
        } catch (\Throwable $exception) {
            $import->update(['status' => 'failed', 'error_count' => max(1, $errors)]);
            throw $exception;
        } finally {
            fclose($stream);
        }

        return $import->fresh('records');
    }

    private function validDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function validTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach (['!H:i:s', '!H:i'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($time && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $time->format(strlen($value) === 5 ? 'H:i' : 'H:i:s') === $value) {
                return strlen($value) === 5 ? $value.':00' : $value;
            }
        }

        return null;
    }
}
