<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeEmploymentHistory;
use App\Models\EmployeeImport;
use App\Models\EmployeeImportMappingEvent;
use App\Models\EmployeeImportRow;
use App\Models\EmployeeServiceProfile;
use App\Models\Position;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class EmployeeWorkbookImportService
{
    private const MAX_ROWS = 1000;

    private const MAX_COLUMNS = 60;

    public function stage(UploadedFile $file, int $userId): EmployeeImport
    {
        $this->validateArchive($file->getRealPath());
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is not a readable XLSX workbook.']);
        }
        if (! $reader instanceof Xlsx) {
            throw ValidationException::withMessages([
                'file' => 'Upload an .xlsx workbook. Other spreadsheet formats are not supported.',
            ]);
        }
        $reader->setReadDataOnly(false);
        try {
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception) {
            throw ValidationException::withMessages(['file' => 'The uploaded workbook could not be parsed. Re-save it as a valid .xlsx file and try again.']);
        }
        $sheet = $spreadsheet->getActiveSheet();
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        if ($lastRow < 2 || $lastRow > self::MAX_ROWS + 1 || $lastColumn > self::MAX_COLUMNS) {
            $spreadsheet->disconnectWorksheets();
            throw ValidationException::withMessages([
                'file' => 'The workbook must contain a header, at least one data row, no more than '.self::MAX_ROWS.' data rows, and no more than '.self::MAX_COLUMNS.' columns.',
            ]);
        }

        $headers = [];
        $headerNames = [];
        for ($column = 1; $column <= $lastColumn; $column++) {
            $cell = $sheet->getCell([$column, 1]);
            $value = trim((string) $cell->getValue());
            $label = $value === '' ? null : $value;
            $headers[] = [
                'index' => $column,
                'letter' => Coordinate::stringFromColumnIndex($column),
                'label' => $label,
                'display_label' => $label ?? 'Unnamed column '.Coordinate::stringFromColumnIndex($column),
            ];
            if ($label !== null) {
                $headerNames[] = mb_strtoupper(preg_replace('/\s+/', ' ', $label));
            }
        }
        $headerCounts = array_count_values($headerNames);
        $requiredHeaders = [
            'NO.', 'NAME', 'POSITION LEVEL', 'BDATE', 'CAT', 'GENDER',
            'NATURE OF APPOINTMENT', 'LATEST POSITION', '1ST POSITION',
            '2ND POSITION', '3RD POSITION', '4TH POSITION', 'DATE APPOINTED (1ST)',
            'DATE APPOINTED (2ND)', 'DATE APPOINTED (3RD)', 'DATE APPOINTED (4TH)',
            'LATEST DATE APPOINTED', 'ELIGIBILITY/PERCENT', 'LICENSE- DATE EXPIRATION',
            'EDUCATIONAL ATTAINMENT', 'YEARS IN SERVICE',
            'EXPECTED DATE OF MANDATORY RETIREMENT', 'AGE',
        ];
        $missingHeaders = array_values(array_filter(
            $requiredHeaders,
            fn (string $required) => ! isset($headerCounts[$required])
        ));
        if ($missingHeaders || ($headerCounts['OFFICE'] ?? 0) !== 2 || ($headerCounts['YEARS IN SERVICE'] ?? 0) < 2) {
            $spreadsheet->disconnectWorksheets();
            throw ValidationException::withMessages([
                'file' => 'Workbook headers are incomplete or ambiguous. Required headings include employee number, name, appointment history, and exactly two OFFICE columns.',
            ]);
        }

        $filePath = $file->store('employee-imports', 'local');
        if (! $filePath) {
            $spreadsheet->disconnectWorksheets();
            throw new \RuntimeException('The uploaded workbook could not be stored securely.');
        }

        try {
            $import = DB::transaction(function () use ($file, $filePath, $userId, $headers, $sheet, $lastRow) {
                $import = EmployeeImport::create([
                    'uploaded_by' => $userId,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'source_file_path' => $filePath,
                    'source_sha256' => hash_file('sha256', $file->getRealPath()),
                    'status' => 'pending_review',
                    'source_headers' => $headers,
                    'row_count' => $lastRow - 1,
                ]);
                $seenEmployeeNos = [];
                $duplicateCount = 0;
                $reviewCount = 0;

                for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
                    $sourceData = [];
                    foreach ($headers as $header) {
                        $cell = $sheet->getCell([$header['index'], $rowNumber]);
                        $raw = $cell->getValue();
                        $isFormula = $cell->getDataType() === 'f';
                        $display = $isFormula ? (string) $raw : (string) $cell->getFormattedValue();
                        $sourceData[] = [
                            'column' => $header['letter'],
                            'header' => $header['label'],
                            'value' => is_scalar($raw) || $raw === null ? $raw : (string) $raw,
                            'display_value' => $display,
                            'data_type' => $cell->getDataType(),
                            'number_format' => $cell->getStyle()->getNumberFormat()->getFormatCode(),
                        ];
                    }

                    $employeeNumber = $this->cellByHeader($sourceData, 'NO.');
                    $employeeNumber = trim((string) ($employeeNumber['display_value'] ?? ''));
                    $issues = [];
                    if ($employeeNumber === '') {
                        $issues[] = 'Employee reference number is missing.';
                    } else {
                        $key = mb_strtolower($employeeNumber);
                        if (isset($seenEmployeeNos[$key])) {
                            $issues[] = 'Duplicate employee reference number within this workbook.';
                        } else {
                            $seenEmployeeNos[$key] = true;
                        }
                        if (Employee::withTrashed()->where('employee_no', $employeeNumber)->exists()) {
                            $issues[] = 'Employee reference number already exists; existing records will not be overwritten.';
                        }
                    }
                    if (trim((string) ($this->cellByHeader($sourceData, 'NAME')['display_value'] ?? '')) === '') {
                        $issues[] = 'Employee name is missing.';
                    }
                    foreach ($sourceData as $cell) {
                        if ($cell['data_type'] === 'f') {
                            $issues[] = 'Formula cells require HRMO review; formulas are preserved and are not evaluated.';
                            break;
                        }
                    }

                    $status = in_array('Duplicate employee reference number within this workbook.', $issues, true)
                        || str_contains(implode(' ', $issues), 'already exists')
                        ? 'duplicate'
                        : ($issues ? 'needs_review' : 'pending_review');
                    $duplicateCount += $status === 'duplicate' ? 1 : 0;
                    $reviewCount += $status === 'needs_review' ? 1 : 0;
                    EmployeeImportRow::create([
                        'employee_import_id' => $import->id,
                        'source_row' => $rowNumber,
                        'source_data' => $sourceData,
                        'issues' => $issues ?: null,
                        'status' => $status,
                    ]);
                }
                $import->update([
                    'skipped_count' => $duplicateCount,
                    'review_count' => $reviewCount,
                ]);

                return $import;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($filePath);
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $import->fresh('rows');
    }

    public function availableOfficeColumns(EmployeeImport $import): array
    {
        return collect($import->source_headers)
            ->filter(fn (array $header) => mb_strtoupper(trim((string) $header['label'])) === 'OFFICE')
            ->values()
            ->all();
    }

    public function ambiguousColumns(EmployeeImport $import): array
    {
        return collect($import->source_headers)
            ->filter(fn (array $header) => $header['label'] === null
                || mb_strtoupper(trim((string) $header['label'])) === 'OFFICE')
            ->values()
            ->all();
    }

    public function officeValues(EmployeeImport $import, int $column): Collection
    {
        return $import->rows()->get()->map(fn (EmployeeImportRow $row) => $this->cellByColumn($row->source_data, $column))
            ->map(fn (?array $cell) => trim((string) ($cell['display_value'] ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    public function positionPairs(EmployeeImport $import, int $officeColumn): Collection
    {
        $positionColumn = $this->headerColumn($import, 'LATEST POSITION');

        return $import->rows()->get()->map(function (EmployeeImportRow $row) use ($officeColumn, $positionColumn) {
            return [
                'office' => trim((string) ($this->cellByColumn($row->source_data, $officeColumn)['display_value'] ?? '')),
                'position' => trim((string) ($this->cellByColumn($row->source_data, $positionColumn)['display_value'] ?? '')),
            ];
        })->filter(fn (array $pair) => $pair['office'] !== '' && $pair['position'] !== '')
            ->unique(fn (array $pair) => hash('sha256', $pair['office']."\0".$pair['position']))
            ->sortBy(fn (array $pair) => $pair['office'].' '.$pair['position'])
            ->values();
    }

    public function saveMapping(EmployeeImport $import, array $mapping, int $userId): void
    {
        if (! in_array($import->status, ['pending_review', 'mapping_approved', 'review_required'], true)) {
            throw ValidationException::withMessages(['mapping' => 'Mappings can only be changed while an import is awaiting review.']);
        }
        $officeColumn = (int) ($mapping['office_source_column'] ?? 0);
        $officeColumns = collect($this->availableOfficeColumns($import))->pluck('index')->all();
        if (! in_array($officeColumn, $officeColumns, true)) {
            throw ValidationException::withMessages(['office_source_column' => 'Choose one of the source OFFICE columns.']);
        }
        if (! in_array($mapping['name_format'] ?? null, ['surname_comma_given', 'given_middle_surname'], true)) {
            throw ValidationException::withMessages(['name_format' => 'Select the employee-name format used in the workbook.']);
        }
        if (! in_array($mapping['employment_status'] ?? null, ['Active', 'On Leave', 'Separated', 'Retired'], true)) {
            throw ValidationException::withMessages(['employment_status' => 'Select the employment status HRMO has verified for these records.']);
        }
        $dateSourceColumn = isset($mapping['date_source_column']) && $mapping['date_source_column'] !== ''
            ? (int) $mapping['date_source_column']
            : null;
        $dateTarget = $mapping['date_target'] ?? 'source_only';
        $dateTargetFields = ['source_only', 'date_of_birth', 'date_hired', 'license_expiration_date', 'expected_retirement_date'];
        if (! in_array($dateTarget, $dateTargetFields, true)) {
            throw ValidationException::withMessages(['date_target' => 'Choose a supported date field or retain the source value only.']);
        }
        $blankColumns = collect($this->ambiguousColumns($import))
            ->filter(fn (array $header) => $header['label'] === null)
            ->pluck('index')->all();
        if ($dateTarget !== 'source_only' && ! in_array($dateSourceColumn, $blankColumns, true)) {
            throw ValidationException::withMessages(['date_source_column' => 'Choose an unnamed source column before mapping its date.']);
        }

        $departments = $mapping['department_map'] ?? [];
        foreach ($this->officeValues($import, $officeColumn) as $value) {
            $key = hash('sha256', $value);
            $departmentId = filter_var($departments[$key] ?? null, FILTER_VALIDATE_INT);
            $department = $departmentId ? Department::query()->where('is_active', true)->find($departmentId) : null;
            if (! $department) {
                throw ValidationException::withMessages([
                    "department_map.{$key}" => 'Map every source office value to an existing active department.',
                ]);
            }
        }

        $positions = $mapping['position_map'] ?? [];
        foreach ($this->positionPairs($import, $officeColumn) as $pair) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            $positionId = filter_var($positions[$key] ?? null, FILTER_VALIDATE_INT);
            $position = $positionId ? Position::query()->where('is_active', true)->find($positionId) : null;
            $departmentId = (int) $departments[hash('sha256', $pair['office'])];
            if (! $position || ($position->department_id && $position->department_id !== $departmentId)) {
                throw ValidationException::withMessages([
                    "position_map.{$key}" => 'Map every latest-position value to an active position in its mapped department.',
                ]);
            }
        }

        $approvedMapping = [
            'office_source_column' => $officeColumn,
            'name_format' => $mapping['name_format'],
            'employment_status' => $mapping['employment_status'],
            'date_source_column' => $dateSourceColumn,
            'date_target' => $dateTarget,
            'ambiguous_field_notes' => array_intersect_key(
                $mapping['ambiguous_field_notes'] ?? [],
                array_fill_keys(array_map(
                    fn (array $header) => (string) $header['index'],
                    $this->ambiguousColumns($import)
                ), true)
            ),
            'department_map' => array_intersect_key($departments, array_fill_keys(
                $this->officeValues($import, $officeColumn)->map(fn (string $value) => hash('sha256', $value))->all(),
                true
            )),
            'position_map' => array_intersect_key($positions, array_fill_keys(
                $this->positionPairs($import, $officeColumn)->map(fn (array $pair) => hash('sha256', $pair['office']."\0".$pair['position']))->all(),
                true
            )),
        ];

        DB::transaction(function () use ($import, $approvedMapping, $userId) {
            $locked = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($locked->status, ['pending_review', 'mapping_approved', 'review_required'], true)) {
                throw ValidationException::withMessages(['mapping' => 'This import is no longer available for mapping review.']);
            }
            $locked->update([
                'field_mappings' => $approvedMapping,
                'mapping_approved_by' => $userId,
                'mapping_approved_at' => now(),
                'status' => 'mapping_approved',
            ]);
            EmployeeImportMappingEvent::create([
                'employee_import_id' => $locked->id,
                'changed_by' => $userId,
                'mapping' => $approvedMapping,
            ]);
            $this->refreshPreview($locked, $userId);
        });
    }

    public function confirm(EmployeeImport $import, int $userId): EmployeeImport
    {
        if (! in_array($import->status, ['mapping_approved', 'review_required'], true) || ! $import->field_mappings) {
            throw ValidationException::withMessages(['mapping' => 'HRMO must review and approve all field mappings before import.']);
        }

        return DB::transaction(function () use ($import, $userId) {
            $lockedImport = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($lockedImport->status, ['mapping_approved', 'review_required'], true)
                || ! $lockedImport->field_mappings || ! $lockedImport->previewed_at) {
                throw ValidationException::withMessages(['import' => 'This workbook has already been processed or changed.']);
            }

            $mapping = $lockedImport->field_mappings;
            $imported = 0;
            $skipped = $lockedImport->rows()->where('status', 'duplicate')->count();
            $needsReview = 0;
            foreach ($lockedImport->rows()->where('status', 'pending_review')->orderBy('source_row')->lockForUpdate()->get() as $row) {
                $prepared = $this->prepareEmployeeData($row, $mapping);
                if ($prepared['issues']) {
                    $row->update(['status' => 'needs_review', 'issues' => $prepared['issues']]);
                    $needsReview++;

                    continue;
                }
                if (Employee::withTrashed()->where('employee_no', $prepared['employee']['employee_no'])->exists()) {
                    $row->update([
                        'status' => 'duplicate',
                        'issues' => ['Employee reference number already exists; existing records were not changed.'],
                    ]);
                    $skipped++;

                    continue;
                }

                $employee = Employee::create($prepared['employee']);
                $prepared['profile']['employee_id'] = $employee->id;
                $prepared['profile']['employee_import_id'] = $lockedImport->id;
                $prepared['profile']['source_row'] = $row->source_row;
                EmployeeServiceProfile::create($prepared['profile']);

                foreach ($prepared['history'] as $history) {
                    EmployeeEmploymentHistory::create([
                        ...$history,
                        'employee_id' => $employee->id,
                        'employee_import_id' => $lockedImport->id,
                        'source_row' => $row->source_row,
                    ]);
                }

                $row->update(['employee_id' => $employee->id, 'status' => 'imported', 'issues' => null]);
                $imported++;
            }

            $needsReview = $lockedImport->rows()->where('status', 'needs_review')->count();
            $skipped = $lockedImport->rows()->where('status', 'duplicate')->count();
            $lockedImport->update([
                'status' => $needsReview ? 'review_required' : 'completed',
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
                'imported_count' => $lockedImport->imported_count + $imported,
                'skipped_count' => $skipped,
                'review_count' => $needsReview,
            ]);

            return $lockedImport->fresh();
        });
    }

    public function saveRowReview(EmployeeImport $import, EmployeeImportRow $row, array $name, int $userId): void
    {
        if ($row->employee_import_id !== $import->id || $row->status === 'duplicate' || $row->status === 'imported') {
            throw ValidationException::withMessages(['row' => 'This source row cannot be edited in its current state.']);
        }

        DB::transaction(function () use ($import, $row, $name, $userId) {
            $lockedImport = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($lockedImport->status, ['pending_review', 'mapping_approved', 'review_required'], true)) {
                throw ValidationException::withMessages(['row' => 'Rows can only be reviewed before the import is complete.']);
            }
            $lockedRow = EmployeeImportRow::query()
                ->where('employee_import_id', $lockedImport->id)
                ->lockForUpdate()
                ->findOrFail($row->id);
            if (in_array($lockedRow->status, ['duplicate', 'imported'], true)) {
                throw ValidationException::withMessages(['row' => 'This source row cannot be edited in its current state.']);
            }
            $lockedRow->update([
                'review_data' => ['name' => $name],
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'status' => 'pending_review',
                'issues' => null,
            ]);
            if ($lockedImport->field_mappings) {
                $this->refreshPreview($lockedImport, $userId);
            }
        });
    }

    private function refreshPreview(EmployeeImport $import, int $userId): void
    {
        $mapping = $import->field_mappings;
        if (! $mapping) {
            throw ValidationException::withMessages(['mapping' => 'Approve source mappings before previewing rows.']);
        }

        $needsReview = 0;
        foreach ($import->rows()->whereNotIn('status', ['duplicate', 'imported'])->orderBy('source_row')->lockForUpdate()->get() as $row) {
            $issues = $this->prepareEmployeeData($row, $mapping)['issues'];
            $needsReview += $issues ? 1 : 0;
            $row->update([
                'status' => $issues ? 'needs_review' : 'pending_review',
                'issues' => $issues ?: null,
            ]);
        }
        $import->update([
            'previewed_by' => $userId,
            'previewed_at' => now(),
            'review_count' => $needsReview,
        ]);
    }

    public function parsedName(EmployeeImportRow $row, string $format): ?array
    {
        return $this->parseName(trim((string) ($this->cellByHeader($row->source_data, 'NAME')['display_value'] ?? '')), $format);
    }

    private function prepareEmployeeData(EmployeeImportRow $row, array $mapping): array
    {
        $data = $row->source_data;
        $officeCell = $this->cellByColumn($data, (int) $mapping['office_source_column']);
        $officeValue = trim((string) ($officeCell['display_value'] ?? ''));
        $positionColumn = $this->headerColumnFromData($data, 'LATEST POSITION');
        $positionValue = trim((string) ($this->cellByColumn($data, $positionColumn)['display_value'] ?? ''));
        $departmentId = $mapping['department_map'][hash('sha256', $officeValue)] ?? null;
        $positionId = $mapping['position_map'][hash('sha256', $officeValue."\0".$positionValue)] ?? null;
        $name = $row->review_data['name'] ?? $this->parseName(
            trim((string) ($this->cellByHeader($data, 'NAME')['display_value'] ?? '')),
            $mapping['name_format']
        );
        $issues = [];
        foreach ($data as $cell) {
            if ($cell['data_type'] === 'f') {
                $issues[] = 'Formula cells require HRMO review; formulas are preserved and are not evaluated.';
                break;
            }
        }
        if (! $name) {
            $issues[] = 'Employee name does not match the HRMO-approved name format.';
        }
        if ($officeValue === '' || ! $departmentId) {
            $issues[] = 'Office value is blank or has no approved department mapping.';
        } elseif (! Department::query()->whereKey($departmentId)->where('is_active', true)->exists()) {
            $issues[] = 'The mapped department is no longer active; HRMO review is required.';
        }
        if ($positionValue === '' || ! $positionId) {
            $issues[] = 'Latest position is blank or has no approved position mapping.';
        } elseif (! Position::query()->whereKey($positionId)->where('is_active', true)
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id')->orWhere('department_id', $departmentId);
            })->exists()) {
            $issues[] = 'The mapped position is no longer active or does not match the department.';
        }

        $birthDateCell = $this->cellByHeader($data, 'BDATE');
        $birthDate = $this->dateValue($birthDateCell);
        $mappedAmbiguousCell = $mapping['date_target'] !== 'source_only'
            ? $this->cellByColumn($data, (int) $mapping['date_source_column'])
            : null;
        $mappedAmbiguousDate = $this->dateValue($mappedAmbiguousCell);
        $hasMappedAmbiguousDate = trim((string) ($mappedAmbiguousCell['display_value'] ?? '')) !== '';
        if ($hasMappedAmbiguousDate && ! $mappedAmbiguousDate) {
            $issues[] = 'The HRMO-mapped unnamed date could not be interpreted; review this row.';
        }
        if ($mapping['date_target'] === 'date_of_birth' && $hasMappedAmbiguousDate) {
            $birthDate = $mappedAmbiguousDate;
        }
        if ($birthDateCell && trim((string) $birthDateCell['display_value']) !== '') {
            if (! $birthDate || $birthDate >= today()->toDateString()) {
                $issues[] = 'Birth date is invalid or not in the past.';
            }
        }
        $dateHired = $this->dateValue($this->cellByHeader($data, 'DATE APPOINTED (1ST)'));
        $retirementCell = $this->cellByHeader($data, 'EXPECTED DATE OF MANDATORY RETIREMENT');
        $retirementDate = $this->dateValue($retirementCell);
        if ($retirementCell && trim((string) $retirementCell['display_value']) !== '' && ! $retirementDate) {
            $issues[] = 'Expected retirement date could not be interpreted; HRMO review is required.';
        }
        if ($mapping['date_target'] === 'date_hired' && $hasMappedAmbiguousDate) {
            $dateHired = $mappedAmbiguousDate;
        }
        $history = $this->employmentHistory($data, $issues);

        $employeeNumber = trim((string) ($this->cellByHeader($data, 'NO.')['display_value'] ?? ''));
        if ($employeeNumber === '') {
            $issues[] = 'Employee reference number is required.';
        }

        $licenseCell = $this->cellByHeader($data, 'LICENSE- DATE EXPIRATION');
        $licenseExpirationDate = $licenseCell
            && is_numeric($licenseCell['value'])
            && ExcelDate::isDateTimeFormatCode((string) $licenseCell['number_format'])
            ? $this->dateValue($licenseCell)
            : null;
        $profile = [
            'position_level' => $this->displayByHeader($data, 'POSITION LEVEL'),
            'source_classification' => $this->displayByHeader($data, 'CAT'),
            'eligibility_rating' => $this->displayByHeader($data, 'ELIGIBILITY/PERCENT'),
            'professional_license' => $this->displayByHeader($data, 'LICENSE- DATE EXPIRATION'),
            'license_expiration_date' => $mapping['date_target'] === 'license_expiration_date' && $hasMappedAmbiguousDate
                ? $mappedAmbiguousDate
                : $licenseExpirationDate,
            'educational_attainment' => $this->displayByHeader($data, 'EDUCATIONAL ATTAINMENT'),
            'years_in_service' => $this->displayByHeader($data, 'YEARS IN SERVICE'),
            'expected_retirement_date' => $mapping['date_target'] === 'expected_retirement_date' && $hasMappedAmbiguousDate
                ? $mappedAmbiguousDate
                : $retirementDate,
            'age_at_source' => $this->displayByHeader($data, 'AGE'),
            'additional_service_years' => $this->displayByHeader($data, 'YEARS IN SERVICE', 2),
        ];

        return [
            'issues' => array_values(array_unique($issues)),
            'employee' => [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'employee_no' => $employeeNumber,
                'first_name' => $name['first_name'] ?? '',
                'middle_name' => $name['middle_name'] ?? null,
                'last_name' => $name['last_name'] ?? '',
                'suffix' => $name['suffix'] ?? null,
                'sex' => $this->displayByHeader($data, 'GENDER'),
                'date_of_birth' => $birthDate,
                'employment_status' => $mapping['employment_status'],
                'employment_type' => $this->displayByHeader($data, 'NATURE OF APPOINTMENT'),
                'date_hired' => $dateHired,
            ],
            'profile' => $profile,
            'history' => $history,
        ];
    }

    private function employmentHistory(array $data, array &$issues): array
    {
        $fields = [
            ['1ST POSITION', 'DATE APPOINTED (1ST)'],
            ['2ND POSITION', 'DATE APPOINTED (2ND)'],
            ['3RD POSITION', 'DATE APPOINTED (3RD)'],
            ['4TH POSITION', 'DATE APPOINTED (4TH)'],
            ['LATEST POSITION', 'LATEST DATE APPOINTED'],
        ];
        $history = [];
        foreach ($fields as $index => [$positionHeader, $dateHeader]) {
            $position = trim((string) ($this->displayByHeader($data, $positionHeader) ?? ''));
            $dateCell = $this->cellByHeader($data, $dateHeader);
            $date = $this->dateValue($dateCell);
            if ($dateCell && trim((string) ($dateCell['display_value'] ?? '')) !== '' && ! $date) {
                $issues[] = 'An appointment-history date could not be interpreted; HRMO review is required.';
            }
            if ($position !== '' || $date !== null) {
                if ($position === '') {
                    $issues[] = 'An appointment date has no associated position; HRMO review is required.';

                    continue;
                }
                $history[] = [
                    'position_title' => $position,
                    'appointment_date' => $date,
                    'sequence' => $index + 1,
                ];
            }
        }

        return $history;
    }

    private function parseName(string $value, string $format): ?array
    {
        if ($value === '') {
            return null;
        }
        if ($format === 'surname_comma_given') {
            if (! str_contains($value, ',')) {
                return null;
            }
            [$lastName, $givenNames] = array_map('trim', explode(',', $value, 2));
            $suffix = $this->extractSuffix($lastName);
            if ($suffix !== null) {
                $lastName = trim((string) preg_replace('/\s+'.preg_quote($suffix, '/').'\.?$/i', '', $lastName));
            }
            $givenParts = preg_split('/\s+/', $givenNames, -1, PREG_SPLIT_NO_EMPTY);

            return $lastName !== '' && $givenParts
                ? [
                    'first_name' => array_shift($givenParts),
                    'middle_name' => implode(' ', $givenParts) ?: null,
                    'last_name' => $lastName,
                    'suffix' => $suffix,
                ]
                : null;
        }

        if (str_contains($value, ',')) {
            return null;
        }
        $parts = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) {
            return null;
        }
        $suffix = $this->extractSuffix(end($parts));
        if ($suffix !== null) {
            array_pop($parts);
        }
        $lastName = array_pop($parts);

        return [
            'first_name' => array_shift($parts),
            'middle_name' => implode(' ', $parts) ?: null,
            'last_name' => $lastName,
            'suffix' => $suffix,
        ];
    }

    private function extractSuffix(string $value): ?string
    {
        $suffixes = ['Jr.', 'Jr', 'Sr.', 'Sr', 'II', 'III', 'IV', 'V'];
        foreach ($suffixes as $suffix) {
            if (mb_strtolower(trim($value)) === mb_strtolower($suffix)) {
                return rtrim($suffix, '.');
            }
        }

        return null;
    }

    private function dateValue(?array $cell): ?string
    {
        if (! $cell || $cell['value'] === null || trim((string) $cell['display_value']) === '') {
            return null;
        }
        if (is_numeric($cell['value']) && ExcelDate::isDateTimeFormatCode((string) $cell['number_format'])) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $cell['value'])->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $value = trim((string) $cell['display_value']);
        $numberFormat = mb_strtolower((string) $cell['number_format']);
        $formats = str_contains($numberFormat, 'mm/dd')
            ? ['!m/d/Y', '!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y']
            : (str_contains($numberFormat, 'dd/mm')
                ? ['!d/m/Y', '!Y-m-d', '!m/d/Y', '!d-m-Y', '!d.m.Y']
                : ['!Y-m-d', '!d/m/Y', '!m/d/Y', '!d-m-Y', '!d.m.Y']);
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function displayByHeader(array $data, string $header, int $occurrence = 1): ?string
    {
        return $this->cellByHeader($data, $header, $occurrence)['display_value'] ?? null;
    }

    private function cellByHeader(array $data, string $header, int $occurrence = 1): ?array
    {
        $wanted = mb_strtoupper(preg_replace('/\s+/', ' ', trim($header)));
        foreach ($data as $cell) {
            $actual = mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) ($cell['header'] ?? ''))));
            if ($actual === $wanted && --$occurrence === 0) {
                return $cell;
            }
        }

        return null;
    }

    private function cellByColumn(array $data, int $column): ?array
    {
        $letter = Coordinate::stringFromColumnIndex($column);

        foreach ($data as $cell) {
            if ($cell['column'] === $letter) {
                return $cell;
            }
        }

        return null;
    }

    private function headerColumn(EmployeeImport $import, string $header): int
    {
        foreach ($import->source_headers as $sourceHeader) {
            if (mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) ($sourceHeader['label'] ?? ''))))
                === mb_strtoupper(preg_replace('/\s+/', ' ', trim($header)))) {
                return (int) $sourceHeader['index'];
            }
        }

        throw ValidationException::withMessages(['file' => 'The workbook is missing the required latest-position column.']);
    }

    private function headerColumnFromData(array $data, string $header): int
    {
        foreach ($data as $cell) {
            if (mb_strtoupper(preg_replace('/\s+/', ' ', trim((string) ($cell['header'] ?? ''))))
                === mb_strtoupper(preg_replace('/\s+/', ' ', trim($header)))) {
                return Coordinate::columnIndexFromString($cell['column']);
            }
        }

        return 0;
    }

    private function validateArchive(string $path): void
    {
        $archive = new \ZipArchive;
        if ($archive->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is not a readable XLSX workbook.']);
        }
        try {
            if ($archive->numFiles < 1 || $archive->numFiles > 1000) {
                throw ValidationException::withMessages(['file' => 'The workbook contains too many internal files.']);
            }
            $uncompressedBytes = 0;
            foreach (range(0, $archive->numFiles - 1) as $index) {
                $stat = $archive->statIndex($index);
                if (! $stat) {
                    throw ValidationException::withMessages(['file' => 'The workbook archive is invalid.']);
                }
                $uncompressedBytes += $stat['size'];
                if ($uncompressedBytes > 50 * 1024 * 1024) {
                    throw ValidationException::withMessages(['file' => 'The workbook expands beyond the permitted size.']);
                }
                if (preg_match('~(^|/)\.\.?(/|$)~', $stat['name'])) {
                    throw ValidationException::withMessages(['file' => 'The workbook contains an invalid archive path.']);
                }
            }
        } finally {
            $archive->close();
        }
    }
}
