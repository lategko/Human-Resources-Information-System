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
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class EmployeeWorkbookImportService
{
    private const MAX_ROWS = 5000;

    private const MAX_COLUMNS = 60;

    private const MAX_WORKSHEET_ROWS = 10000;

    private const HEADER_SCAN_ROWS = 10;

    private const READY_ROW_STATUSES = ['ready', 'ready_to_update'];

    private const IMPORT_BATCH_SIZE = 50;

    public function __construct(private readonly EmployeeUsernameGenerator $usernameGenerator) {}

    public function stage(UploadedFile $file, int $userId): EmployeeImport
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if ($extension === 'xlsx') {
            $this->validateArchive($file->getRealPath());
        } elseif ($extension !== 'xls') {
            throw ValidationException::withMessages(['file' => 'Upload an .xlsx or .xls workbook.']);
        }
        try {
            $reader = IOFactory::createReaderForFile($file->getRealPath());
            if (! $reader instanceof Xlsx && ! $reader instanceof Xls) {
                throw ValidationException::withMessages(['file' => 'The uploaded file is not a supported Excel workbook.']);
            }
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($file->getRealPath());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'The uploaded file is not a readable XLS or XLSX workbook.']);
        }

        try {
            $analysis = $this->analyzeWorkbook($spreadsheet);
        } catch (ValidationException $exception) {
            $spreadsheet->disconnectWorksheets();
            throw $exception;
        } catch (\Throwable $exception) {
            $spreadsheet->disconnectWorksheets();
            report($exception);
            throw ValidationException::withMessages([
                'file' => 'The workbook could not be analyzed. Check that it is a valid Excel file and that its employee sheet is readable.',
            ]);
        }
        $spreadsheet->disconnectWorksheets();

        $filePath = $file->store('employee-imports', 'local');
        if (! $filePath) {
            throw new \RuntimeException('The uploaded workbook could not be stored securely.');
        }

        try {
            $import = DB::transaction(function () use ($file, $filePath, $userId, $analysis) {
                $import = EmployeeImport::create([
                    'uploaded_by' => $userId,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'source_file_path' => $filePath,
                    'source_sha256' => hash_file('sha256', $file->getRealPath()),
                    'status' => 'mapping_required',
                    'source_headers' => $analysis['headers'],
                    'worksheet_name' => $analysis['worksheet_name'],
                    'header_row' => $analysis['header_row'],
                    'worksheet_names' => $analysis['worksheet_names'],
                    'row_count' => count($analysis['rows']),
                    'source_row_count' => $analysis['source_row_count'],
                    'excluded_rows_count' => $analysis['excluded_rows_count'],
                    'plantilla_item_count' => $analysis['plantilla_item_count'],
                    'vacant_count' => $analysis['vacant_count'],
                    'excluded_office_count' => $analysis['excluded_office_count'],
                    'excluded_records' => $analysis['excluded_records'],
                    'position_reference_count' => count($analysis['position_reference_titles']),
                    'position_reference_titles' => $analysis['position_reference_titles'],
                ]);
                $seenEmployeeNos = [];
                $seenIdentityHashes = [];
                $seenPlantillaItems = [];
                $existingPlantillaItemOwners = $analysis['format'] === 'plantilla'
                    ? EmployeeServiceProfile::query()
                        ->whereNotNull('plantilla_item_no')
                        ->get(['employee_id', 'plantilla_item_no'])
                        ->groupBy(fn (EmployeeServiceProfile $profile) => $this->normalizeMatchValue((string) $profile->plantilla_item_no))
                        ->map(fn (Collection $profiles) => $profiles->pluck('employee_id')->all())
                        ->all()
                    : [];
                $duplicateCount = 0;
                $reviewCount = 0;

                foreach ($analysis['rows'] as $sourceRow) {
                    $sourceData = $sourceRow['data'];
                    $identity = $sourceRow['identity'];
                    $itemNumberKey = $analysis['format'] === 'plantilla'
                        ? $this->normalizeMatchValue((string) ($identity['item_number'] ?? ''))
                        : '';
                    $employeeNumber = $analysis['format'] === 'plantilla'
                        ? ''
                        : trim((string) ($this->cellByHeader($sourceData, 'NO.')['display_value'] ?? ''));
                    $issues = $identity['error'] ? [$identity['error']] : [];
                    $duplicatePlantillaItem = $itemNumberKey !== '' && isset($seenPlantillaItems[$itemNumberKey]);
                    if ($itemNumberKey !== '') {
                        if ($duplicatePlantillaItem) {
                            $issues[] = 'Duplicate plantilla item number within this workbook.';
                        } else {
                            $seenPlantillaItems[$itemNumberKey] = true;
                        }
                    }
                    $existingEmployee = null;
                    if ($employeeNumber === '') {
                        if ($analysis['format'] === 'legacy') {
                            $issues[] = 'Employee reference number is missing.';
                        }
                    } else {
                        $key = mb_strtolower($employeeNumber);
                        if (isset($seenEmployeeNos[$key])) {
                            $issues[] = 'Duplicate employee reference number within this workbook.';
                        } else {
                            $seenEmployeeNos[$key] = true;
                        }
                        $existingEmployee = Employee::withTrashed()
                            ->with('user')
                            ->whereRaw('LOWER(employee_no) = ?', [$key])
                            ->first();
                        if ($existingEmployee?->trashed()) {
                            $issues[] = 'An archived employee already uses this employee number. Review the archived record before importing.';
                        }
                        if ($existingEmployee?->user?->role === 'admin') {
                            $issues[] = 'This employee number belongs to a protected Admin account. Imports cannot change that employee or account.';
                        }
                    }
                    if ($analysis['format'] === 'plantilla' && $identity['hash']) {
                        $existingProfile = EmployeeServiceProfile::query()
                            ->with('employee.user')
                            ->where('source_identity_hash', $identity['hash'])
                            ->first();
                        $existingEmployee ??= $existingProfile?->employee;
                    }
                    $itemOwners = $itemNumberKey !== '' ? ($existingPlantillaItemOwners[$itemNumberKey] ?? []) : [];
                    $itemNumberOwnedByAnotherEmployee = $itemOwners
                        && ! in_array($existingEmployee?->id, $itemOwners, true);
                    $existingMayor = $this->existingMayorForSourceRow($sourceData, $analysis['format']);
                    $matchesExistingMayor = $existingMayor
                        && ! $itemNumberOwnedByAnotherEmployee
                        && (! $existingEmployee || (int) $existingEmployee->user_id === (int) $existingMayor->id);
                    if ($matchesExistingMayor) {
                        $issues[] = 'This record matches the existing Mayor account and will be skipped to avoid creating a duplicate account.';
                    }
                    if ($existingEmployee?->user?->role === 'admin'
                        && ! in_array('This employee number belongs to a protected Admin account. Imports cannot change that employee or account.', $issues, true)) {
                        $issues[] = 'This employee number belongs to a protected Admin account. Imports cannot change that employee or account.';
                    }
                    if ($itemNumberOwnedByAnotherEmployee) {
                        $issues[] = 'This plantilla item number is already assigned to another employee.';
                    }
                    if ($analysis['format'] === 'legacy'
                        && trim((string) ($this->cellByHeader($sourceData, 'NAME')['display_value'] ?? '')) === '') {
                        $issues[] = 'Employee name is missing.';
                    }
                    if ($identity['hash'] && isset($seenIdentityHashes[$identity['hash']])) {
                        $issues[] = 'The same source identity appears more than once in this workbook.';
                    } elseif ($identity['hash']) {
                        $seenIdentityHashes[$identity['hash']] = true;
                    }
                    $status = in_array('Duplicate employee reference number within this workbook.', $issues, true)
                        || in_array('The same source identity appears more than once in this workbook.', $issues, true)
                        || $duplicatePlantillaItem
                        || $existingEmployee?->trashed()
                        || $existingEmployee?->user?->role === 'admin'
                        || $matchesExistingMayor
                        ? 'duplicate'
                        : ($issues
                            ? 'needs_review'
                            : ($existingEmployee ? 'existing_to_update' : 'unvalidated'));
                    $duplicateCount += $status === 'duplicate' ? 1 : 0;
                    $reviewCount += $status === 'needs_review' ? 1 : 0;
                    EmployeeImportRow::create([
                        'employee_import_id' => $import->id,
                        'source_row' => $sourceRow['source_row'],
                        'source_data' => $sourceData,
                        'review_data' => [
                            'identity' => $identity,
                            'classification' => [
                                'action' => $existingEmployee ? 'update' : 'create',
                                'account_action' => $existingEmployee?->user_id ? 'existing' : 'create',
                            ],
                        ],
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
        }

        return $import->fresh('rows');
    }

    private function analyzeWorkbook(Spreadsheet $spreadsheet): array
    {
        $sheets = $spreadsheet->getAllSheets();
        $worksheetNames = array_map(fn ($sheet) => $sheet->getTitle(), $sheets);
        usort($sheets, fn ($left, $right) => (
            $this->normalizeMatchValue($right->getTitle()) === 'PLANTILLA'
        ) <=> (
            $this->normalizeMatchValue($left->getTitle()) === 'PLANTILLA'
        ));

        $detected = null;
        foreach ($sheets as $sheet) {
            $bounds = $this->worksheetBounds($sheet);
            if ($bounds['max_row'] < 2) {
                continue;
            }
            for ($headerRow = 1; $headerRow <= min(self::HEADER_SCAN_ROWS, $bounds['max_row']); $headerRow++) {
                $labels = [];
                for ($column = 1; $column <= $bounds['max_column']; $column++) {
                    $labels[] = $this->normalizeHeader((string) $sheet->getCell([$column, $headerRow])->getFormattedValue());
                }
                $labelSet = array_unique($labels);
                $isPlantilla = count(array_diff([
                    'ORGANIZATIONAL UNIT',
                    'ITEM NUMBER',
                    'POSITION TITLE',
                    'LAST NAME',
                    'FIRST NAME',
                    'MIDDLE NAME',
                ], $labelSet)) === 0;
                $isLegacy = count(array_diff(['NO.', 'NAME', 'LATEST POSITION'], $labelSet)) === 0
                    && in_array('OFFICE', $labelSet, true);

                if ($isPlantilla || $isLegacy) {
                    $detected = [
                        'sheet' => $sheet,
                        'worksheet_name' => $sheet->getTitle(),
                        'header_row' => $headerRow,
                        'format' => $isPlantilla ? 'plantilla' : 'legacy',
                        'bounds' => $bounds,
                    ];
                    break 2;
                }
            }
        }

        if (! $detected) {
            throw ValidationException::withMessages([
                'file' => 'No employee data worksheet was recognized. The workbook must contain a supported Plantilla or HRMO employee header row.',
            ]);
        }

        $sheet = $detected['sheet'];
        $bounds = $detected['bounds'];
        if ($bounds['max_column'] > self::MAX_COLUMNS) {
            throw ValidationException::withMessages([
                'file' => 'The employee worksheet contains more than '.self::MAX_COLUMNS.' populated columns.',
            ]);
        }
        if ($bounds['max_row'] - $detected['header_row'] > self::MAX_WORKSHEET_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'The employee worksheet exceeds the configured row scan limit.',
            ]);
        }

        $headers = [];
        $headerNames = [];
        for ($column = 1; $column <= $bounds['max_column']; $column++) {
            $value = trim((string) $sheet->getCell([$column, $detected['header_row']])->getFormattedValue());
            $label = $value === '' ? null : $value;
            $canonicalLabel = $label === null ? null : $this->canonicalHeader($label);
            $headers[] = [
                'index' => $column,
                'letter' => Coordinate::stringFromColumnIndex($column),
                'label' => $label,
                'canonical_label' => $canonicalLabel,
                'display_label' => $label ?? 'Unnamed column '.Coordinate::stringFromColumnIndex($column),
            ];
            if ($canonicalLabel !== null) {
                $headerNames[] = $canonicalLabel;
            }
        }

        $headerCounts = array_count_values($headerNames);
        if ($detected['format'] === 'plantilla') {
            $requiredHeaders = [
                'OFFICE', 'ITEM NUMBER', 'LATEST POSITION', 'LAST NAME', 'FIRST NAME',
                'MIDDLE NAME', 'GENDER', 'BDATE', 'STATUS',
            ];
            $missingHeaders = array_values(array_filter(
                $requiredHeaders,
                fn (string $required) => ! isset($headerCounts[$required])
            ));
            if ($missingHeaders) {
                throw ValidationException::withMessages([
                    'file' => 'The PLANTILLA worksheet is missing required columns: '.implode(', ', $missingHeaders).'.',
                ]);
            }
        } else {
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
                fn (string $required) => ! isset($headerCounts[$this->canonicalHeader($required)])
            ));
            if ($missingHeaders || ($headerCounts['OFFICE'] ?? 0) < 1 || ($headerCounts['YEARS IN SERVICE'] ?? 0) < 2) {
                throw ValidationException::withMessages([
                    'file' => 'The HRMO employee worksheet is missing required headings such as employee number, name, OFFICE, or appointment history.',
                ]);
            }
        }

        $rows = [];
        $plantillaItemCount = 0;
        $vacantCount = 0;
        $excludedOfficeCount = 0;
        $excludedRecords = [];
        $sourceRowCount = max(0, $bounds['max_row'] - $detected['header_row']);
        for ($rowNumber = $detected['header_row'] + 1; $rowNumber <= $bounds['max_row']; $rowNumber++) {
            $sourceData = [];
            $hasValue = false;
            foreach ($headers as $header) {
                $cell = $sheet->getCell([$header['index'], $rowNumber]);
                $raw = $cell->getValue();
                $isFormula = $cell->getDataType() === 'f';
                $calculationError = false;
                if ($isFormula) {
                    try {
                        $value = $cell->getCalculatedValue();
                        $display = (string) $cell->getFormattedValue();
                    } catch (\Throwable) {
                        $value = $cell->getOldCalculatedValue();
                        $display = $value === null ? (string) $raw : (string) $value;
                        $calculationError = true;
                    }
                } else {
                    $value = $raw;
                    $display = (string) $cell->getFormattedValue();
                }
                $value = is_scalar($value) || $value === null ? $value : (string) $value;
                $hasValue = $hasValue || trim($display) !== '';
                $sourceData[] = [
                    'column' => $header['letter'],
                    'header' => $header['label'],
                    'canonical_header' => $header['canonical_label'],
                    'value' => $value,
                    'display_value' => $display,
                    'data_type' => $cell->getDataType(),
                    'formula' => $isFormula ? (string) $raw : null,
                    'formula_error' => $calculationError,
                    'number_format' => $cell->getStyle()->getNumberFormat()->getFormatCode(),
                ];
            }

            if (! $hasValue) {
                continue;
            }
            if ($detected['format'] === 'plantilla') {
                $itemNumber = trim((string) ($this->cellByHeader($sourceData, 'ITEM NUMBER')['display_value'] ?? ''));
                $hasName = trim((string) ($this->cellByHeader($sourceData, 'LAST NAME')['display_value'] ?? '')) !== ''
                    || trim((string) ($this->cellByHeader($sourceData, 'FIRST NAME')['display_value'] ?? '')) !== '';
                if ($itemNumber === '' && ! $hasName) {
                    continue;
                }

                if ($itemNumber !== '') {
                    $plantillaItemCount++;
                    $office = trim((string) ($this->cellByHeader($sourceData, 'OFFICE')['display_value'] ?? ''));
                    $position = trim((string) ($this->cellByHeader($sourceData, 'LATEST POSITION')['display_value'] ?? ''));

                    if ($this->isExcludedOffice($office)) {
                        $excludedOfficeCount++;
                        $excludedRecords[] = [
                            'source_row' => $rowNumber,
                            'type' => 'excluded_office',
                            'item_number' => $itemNumber,
                            'office' => $office,
                            'position' => $position,
                        ];

                        continue;
                    }

                    if ($this->isVacantPlantillaRow($sourceData)) {
                        $vacantCount++;
                        $excludedRecords[] = [
                            'source_row' => $rowNumber,
                            'type' => 'vacant',
                            'item_number' => $itemNumber,
                            'office' => $office,
                            'position' => $position,
                        ];

                        continue;
                    }
                }
            }

            $rows[] = ['source_row' => $rowNumber, 'data' => $sourceData];
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => 'The employee worksheet contains more than '.self::MAX_ROWS.' occupied employee records.',
            ]);
        }
        if ($rows === [] && ($detected['format'] !== 'plantilla' || $plantillaItemCount === 0)) {
            throw ValidationException::withMessages(['file' => 'No occupied employee records with complete names were found in the recognized worksheet.']);
        }

        if ($detected['format'] === 'plantilla') {
            $rows = $this->attachPlantillaIdentity($rows);
        } else {
            $rows = array_map(fn (array $row) => [
                ...$row,
                'identity' => ['hash' => null, 'type' => null, 'item_number' => null, 'error' => null],
            ], $rows);
        }

        $positionReferenceTitles = $this->positionReferenceTitles($spreadsheet);

        return [
            'format' => $detected['format'],
            'worksheet_name' => $detected['worksheet_name'],
            'worksheet_names' => $worksheetNames,
            'header_row' => $detected['header_row'],
            'headers' => $headers,
            'rows' => $rows,
            'source_row_count' => $sourceRowCount,
            'excluded_rows_count' => count($excludedRecords),
            'plantilla_item_count' => $plantillaItemCount,
            'vacant_count' => $vacantCount,
            'excluded_office_count' => $excludedOfficeCount,
            'excluded_records' => $excludedRecords,
            'position_reference_titles' => $positionReferenceTitles,
        ];
    }

    private function isVacantPlantillaRow(array $sourceData): bool
    {
        foreach ($sourceData as $cell) {
            if (preg_match('/\bVACANT\b/i', (string) ($cell['display_value'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedOffice(string $office): bool
    {
        $normalized = $this->normalizeMatchValue($office);

        return collect(config('hris.employee_import.excluded_department_aliases', []))
            ->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized);
    }

    private function positionReferenceTitles(Spreadsheet $spreadsheet): array
    {
        $sheet = collect($spreadsheet->getAllSheets())
            ->first(fn ($worksheet) => $this->normalizeMatchValue($worksheet->getTitle()) === 'POSITIONS');
        if (! $sheet) {
            return [];
        }

        $titles = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $title = trim((string) $cell->getFormattedValue());
                if ($this->isPositionReferenceTitle($title)) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }

    private function isPositionReferenceTitle(string $title): bool
    {
        $normalized = $this->normalizeMatchValue($title);
        if ($normalized === '' || mb_strlen($title) > 150 || preg_match('/[\r\n]/u', $title)) {
            return false;
        }

        return ! in_array($normalized, [
            'POSITION', 'POSITIONS', 'POSITIONTITLE', 'OFFICIALPOSITIONS', 'REFERENCE',
            'REFERENCEINFORMATION', 'INSTRUCTION', 'INSTRUCTIONS', 'NOTES',
        ], true)
            && ! preg_match('/^(NOTE|INSTRUCTIONS?|PLEASE|SELECT|DO NOT)\b/iu', trim($title));
    }

    private function worksheetBounds($sheet): array
    {
        $maxRow = 0;
        $maxColumn = 0;
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            $value = $cell->getValue();
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }
            [$columnName, $row] = Coordinate::coordinateFromString($coordinate);
            $maxRow = max($maxRow, (int) $row);
            $maxColumn = max($maxColumn, Coordinate::columnIndexFromString($columnName));
        }

        return ['max_row' => $maxRow, 'max_column' => $maxColumn];
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtoupper(preg_replace('/\s+/', ' ', trim($header)));

        return trim((string) preg_replace('/\s*\(\s*\d+\s*\)\s*$/', '', $header));
    }

    private function canonicalHeader(string $header): string
    {
        return match ($this->normalizeHeader($header)) {
            'ORGANIZATIONAL UNIT' => 'OFFICE',
            'POSITION TITLE' => 'LATEST POSITION',
            'LEVEL' => 'POSITION LEVEL',
            'DATE OF BIRTH' => 'BDATE',
            'BIRTHDATE' => 'BDATE',
            'SEX' => 'GENDER',
            'EDUCATIONAL LEVEL' => 'EDUCATIONAL ATTAINMENT',
            'ELIGIBILITY/IES' => 'ELIGIBILITY/PERCENT',
            default => $this->normalizeHeader($header),
        };
    }

    private function attachPlantillaIdentity(array $rows): array
    {
        $identifierCounts = ['GSIS BP NUMBER' => [], 'TIN' => []];
        foreach ($rows as $row) {
            foreach (array_keys($identifierCounts) as $header) {
                $value = $this->normalizeIdentifier((string) ($this->cellByHeader($row['data'], $header)['display_value'] ?? ''));
                if ($value !== '') {
                    $identifierCounts[$header][$value] = ($identifierCounts[$header][$value] ?? 0) + 1;
                }
            }
        }

        foreach ($rows as &$row) {
            $data = $row['data'];
            $gsis = $this->normalizeIdentifier((string) ($this->cellByHeader($data, 'GSIS BP NUMBER')['display_value'] ?? ''));
            $tin = $this->normalizeIdentifier((string) ($this->cellByHeader($data, 'TIN')['display_value'] ?? ''));
            $itemNumber = trim((string) ($this->cellByHeader($data, 'ITEM NUMBER')['display_value'] ?? ''));
            $lastName = $this->normalizeMatchValue((string) ($this->cellByHeader($data, 'LAST NAME')['display_value'] ?? ''));
            $firstName = $this->normalizeMatchValue((string) ($this->cellByHeader($data, 'FIRST NAME')['display_value'] ?? ''));
            $middleName = $this->normalizeMatchValue((string) ($this->cellByHeader($data, 'MIDDLE NAME')['display_value'] ?? ''));
            $birthDate = $this->dateValue($this->cellByHeader($data, 'BDATE'));

            $identityType = null;
            $identityValue = null;
            if ($gsis !== '' && ($identifierCounts['GSIS BP NUMBER'][$gsis] ?? 0) === 1) {
                $identityType = 'gsis_bp';
                $identityValue = 'GSIS:'.$gsis;
            } elseif ($tin !== '' && ($identifierCounts['TIN'][$tin] ?? 0) === 1) {
                $identityType = 'tin';
                $identityValue = 'TIN:'.$tin;
            } elseif ($itemNumber !== '' && $lastName !== '' && $firstName !== '') {
                $identityType = $birthDate ? 'item_name_dob' : 'item_name';
                $identityValue = implode(':', [
                    'PLANTILLA',
                    $this->normalizeMatchValue($itemNumber),
                    $lastName,
                    $firstName,
                    $middleName,
                    $birthDate ?? '',
                ]);
            }

            $identity = [
                'hash' => $identityValue === null
                    ? null
                    : hash_hmac('sha256', $identityValue, (string) config('app.key')),
                'type' => $identityType,
                'item_number' => $itemNumber ?: null,
                'error' => $identityValue === null
                    ? 'A unique GSIS BP number, TIN, or complete name and plantilla item number is required to safely match this record.'
                    : null,
            ];
            $row['identity'] = $identity;
        }
        unset($row);

        return $rows;
    }

    private function normalizeIdentifier(string $value): string
    {
        $normalized = $this->normalizeMatchValue($value);

        return in_array($normalized, ['', 'NONE', 'NA', 'NOTAPPLICABLE', '0'], true) ? '' : $normalized;
    }

    public function availableOfficeColumns(EmployeeImport $import): array
    {
        return collect($import->source_headers)
            ->filter(fn (array $header) => ($header['canonical_label'] ?? $this->canonicalHeader((string) ($header['label'] ?? ''))) === 'OFFICE')
            ->values()
            ->all();
    }

    public function suggestedOfficeColumn(EmployeeImport $import): ?int
    {
        $rows = $import->rows()->get();

        return collect($this->availableOfficeColumns($import))
            ->map(function (array $header) use ($rows) {
                $filled = $rows->filter(
                    fn (EmployeeImportRow $row) => trim((string) ($this->cellByColumn($row->source_data, $header['index'])['display_value'] ?? '')) !== ''
                )->count();

                return ['index' => (int) $header['index'], 'filled' => $filled];
            })
            ->sortByDesc('filled')
            ->first()['index'] ?? null;
    }

    public function ambiguousColumns(EmployeeImport $import): array
    {
        return collect($import->source_headers)
            ->filter(fn (array $header) => $header['label'] === null
                || ($header['canonical_label'] ?? $this->canonicalHeader((string) ($header['label'] ?? ''))) === 'OFFICE')
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
        $salaryGradeHeader = collect($import->source_headers)->first(
            fn (array $header) => ($header['canonical_label'] ?? $this->canonicalHeader((string) ($header['label'] ?? '')))
                === $this->canonicalHeader('SALARY GRADE')
        );
        $salaryGradeColumn = $salaryGradeHeader ? (int) $salaryGradeHeader['index'] : null;

        return $import->rows()->get()->map(function (EmployeeImportRow $row) use ($officeColumn, $positionColumn, $salaryGradeColumn) {
            $name = $this->parsedName($row);

            return [
                'office' => trim((string) ($this->cellByColumn($row->source_data, $officeColumn)['display_value'] ?? '')),
                'position' => trim((string) ($this->cellByColumn($row->source_data, $positionColumn)['display_value'] ?? '')),
                'salary_grade' => $salaryGradeColumn
                    ? trim((string) ($this->cellByColumn($row->source_data, $salaryGradeColumn)['display_value'] ?? ''))
                    : '',
                'affected_records' => [[
                    'source_row' => $row->source_row,
                    'name' => collect($name ?? [])->filter()->implode(' ') ?: 'Name needs review',
                ]],
            ];
        })->filter(fn (array $pair) => $pair['office'] !== '' && $pair['position'] !== '')
            ->groupBy(fn (array $pair) => hash('sha256', $pair['office']."\0".$pair['position']))
            ->map(function (Collection $pairs) {
                $grades = $pairs->pluck('salary_grade')->filter()->unique()->values();
                $pair = $pairs->first();
                $pair['salary_grade'] = $grades->count() === 1 ? $grades->first() : '';
                $pair['salary_grades'] = $grades->all();
                $pair['affected_records'] = $pairs
                    ->flatMap(fn (array $candidate) => $candidate['affected_records'])
                    ->values()
                    ->all();

                return $pair;
            })
            ->sortBy(fn (array $pair) => $pair['office'].' '.$pair['position'])
            ->values();
    }

    public function suggestedDepartmentMap(EmployeeImport $import, int $officeColumn): array
    {
        $departments = $this->officialDepartments();

        return $this->officeValues($import, $officeColumn)->mapWithKeys(function (string $office) use ($departments) {
            $match = $this->departmentMatch($office, $departments);

            return $match['department_id']
                ? [hash('sha256', $office) => $match['department_id']]
                : [];
        })->all();
    }

    public function suggestedPositionMap(
        EmployeeImport $import,
        int $officeColumn,
        array $departmentMap,
        ?Collection $positions = null,
        ?array $referenceCatalog = null
    ): array {
        $positions ??= $this->activePositions();
        $referenceCatalog ??= $this->normalizedPositionReferenceCatalog($import->position_reference_titles ?? []);

        return $this->positionPairs($import, $officeColumn)->mapWithKeys(function (array $pair) use ($departmentMap, $positions, $referenceCatalog) {
            $departmentId = $departmentMap[hash('sha256', $pair['office'])] ?? null;
            $match = $this->positionMatch(
                $pair,
                filter_var($departmentId, FILTER_VALIDATE_INT) ?: null,
                $positions,
                $referenceCatalog
            );
            $key = hash('sha256', $pair['office']."\0".$pair['position']);

            return $match['position_id'] ? [$key => $match['position_id']] : [];
        })->all();
    }

    public function departmentSuggestions(string $office): array
    {
        $normalized = $this->normalizeMatchValue($office);
        if ($normalized === '') {
            return [];
        }

        return $this->officialDepartments()
            ->map(function (Department $department) use ($normalized) {
                $score = collect([$department->name, $department->code, ...($department->aliases ?? [])])
                    ->filter()
                    ->map(fn (string $candidate) => $this->matchScore($normalized, $candidate))
                    ->max() ?? 0;

                return ['id' => $department->id, 'name' => $department->name, 'code' => $department->code, 'score' => $score];
            })
            ->filter(fn (array $candidate) => $candidate['score'] >= 50)
            ->sortByDesc('score')
            ->take(3)
            ->values()
            ->all();
    }

    private function departmentMatch(string $office, Collection $departments): array
    {
        $normalized = $this->normalizeMatchValue($office);
        $directMatches = $departments->filter(fn (Department $department) => (
            $this->normalizeMatchValue($department->name) === $normalized
            || $this->normalizeMatchValue($department->code ?? '') === $normalized
        ));
        $configuredAliases = collect(config('hris.employee_import.department_aliases', []))
            ->filter(fn (string $target, string $alias) => $this->normalizeMatchValue($alias) === $normalized)
            ->values();
        $aliasMatches = $departments->filter(function (Department $department) use ($normalized, $configuredAliases) {
            $databaseAliasMatches = collect($department->aliases ?? [])
                ->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized);
            $configuredAliasMatches = $configuredAliases->contains(
                fn (string $target) => $this->normalizeMatchValue($department->name) === $this->normalizeMatchValue($target)
                    || $this->normalizeMatchValue($department->code ?? '') === $this->normalizeMatchValue($target)
            );

            return $databaseAliasMatches || $configuredAliasMatches;
        });
        $candidateDepartments = $directMatches->merge($aliasMatches)->unique('id')->values();

        if ($directMatches->count() === 1 && $candidateDepartments->count() === 1) {
            $department = $directMatches->first();

            return [
                'department_id' => $department->id,
                'department_name' => $department->name,
                'status' => 'Automatically Matched',
                'reason' => 'Normalized department name or code match',
                'confidence' => 100,
                'suggestions' => [],
                'has_exact_match' => true,
            ];
        }

        if ($directMatches->isEmpty() && $aliasMatches->count() === 1) {
            $department = $aliasMatches->first();

            return [
                'department_id' => $department->id,
                'department_name' => $department->name,
                'status' => 'Matched by Approved Alias',
                'reason' => 'Matches a configured or HR-approved department alias',
                'confidence' => 100,
                'suggestions' => [],
                'has_exact_match' => true,
            ];
        }

        if ($candidateDepartments->isNotEmpty()) {
            $suggestions = $candidateDepartments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'score' => 100,
            ])->all();

            return [
                'department_id' => null,
                'department_name' => null,
                'status' => 'Needs HR Confirmation',
                'reason' => 'More than one existing department matches this name',
                'confidence' => null,
                'suggestions' => $suggestions,
                'has_exact_match' => true,
            ];
        }

        $suggestions = $this->departmentSuggestions($office);

        return [
            'department_id' => null,
            'department_name' => null,
            'status' => $suggestions ? 'Needs HR Confirmation' : 'Unmatched',
            'reason' => $suggestions
                ? 'Possible matches are suggestions only and require HR confirmation'
                : 'No normalized name, approved alias, or sufficiently similar department was found',
            'confidence' => null,
            'suggestions' => $suggestions,
            'has_exact_match' => false,
        ];
    }

    private function positionMatch(
        array $pair,
        ?int $departmentId,
        ?Collection $positions = null,
        array $referenceCatalog = []
    ): array {
        $positions ??= $this->activePositions();
        if (! $departmentId) {
            $suggestions = $this->positionSuggestions($pair['position'], null, $positions);

            return [
                'position_id' => null,
                'position_title' => null,
                'status' => 'Needs HR Confirmation',
                'reason' => 'Confirm the department before matching its position',
                'confidence' => null,
                'suggestions' => $suggestions,
                'has_exact_match' => false,
            ];
        }

        $normalizedTitle = $this->normalizeMatchValue($pair['position']);
        $eligiblePositions = $positions
            ->filter(fn (Position $position) => $position->department_id === null
                || (int) $position->department_id === $departmentId);
        $candidatePositions = $eligiblePositions->filter(fn (Position $position) => (
            $this->normalizeMatchValue($position->title) === $normalizedTitle
            || collect($position->aliases ?? [])
                ->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalizedTitle)
        ));
        $departmentPositions = $candidatePositions->where('department_id', $departmentId);
        if ($departmentPositions->isNotEmpty()) {
            $candidatePositions = $departmentPositions;
        }
        $candidatePositions = $candidatePositions->unique('id')->values();
        $sourceSalaryGrades = collect($pair['salary_grades'] ?? [])
            ->map(fn ($grade) => trim((string) $grade))
            ->filter()
            ->unique()
            ->values();
        $sourceSalaryGrade = $sourceSalaryGrades->count() === 1
            ? $sourceSalaryGrades->first()
            : trim((string) ($pair['salary_grade'] ?? ''));

        if ($candidatePositions->count() > 1 && $sourceSalaryGrade !== ''
            && $candidatePositions->every(fn (Position $position) => trim((string) $position->salary_grade) !== '')) {
            $matchingSalaryGrades = $candidatePositions->filter(
                fn (Position $position) => $this->normalizeMatchValue((string) $position->salary_grade)
                    === $this->normalizeMatchValue($sourceSalaryGrade)
            );
            if ($matchingSalaryGrades->count() === 1) {
                $candidatePositions = $matchingSalaryGrades->values();
            }
        }

        $salaryGradeMismatch = $candidatePositions->count() === 1
            && $sourceSalaryGrade !== ''
            && trim((string) $candidatePositions->first()->salary_grade) !== ''
            && $this->normalizeMatchValue($sourceSalaryGrade)
                !== $this->normalizeMatchValue((string) $candidatePositions->first()->salary_grade);

        if ($candidatePositions->isNotEmpty() && ($sourceSalaryGrades->count() > 1 || $salaryGradeMismatch)) {
            $position = $candidatePositions->first();

            return [
                'position_id' => null,
                'position_title' => null,
                'status' => 'Needs HR Confirmation',
                'reason' => $sourceSalaryGrades->count() > 1
                    ? 'Workbook records show multiple salary grades for this position'
                    : "Workbook salary grade {$sourceSalaryGrade} conflicts with the HRIS position salary grade {$position->salary_grade}",
                'confidence' => null,
                'suggestions' => $candidatePositions->map(fn (Position $candidate) => [
                    'id' => $candidate->id,
                    'title' => $candidate->title,
                    'department' => $candidate->department?->name ?? 'Any department',
                    'salary_grade' => $candidate->salary_grade,
                    'score' => 100,
                ])->all(),
                'has_exact_match' => true,
            ];
        }

        if ($candidatePositions->count() === 1) {
            $position = $candidatePositions->first();
            $isTitleMatch = $this->normalizeMatchValue($position->title) === $normalizedTitle;

            return [
                'position_id' => $position->id,
                'position_title' => $position->title,
                'status' => $isTitleMatch ? 'Automatically Matched' : 'Matched by Approved Alias',
                'reason' => $isTitleMatch
                    ? 'Normalized position title in the confirmed department'
                    : 'Matches an existing HR-approved position alias in the confirmed department',
                'confidence' => 100,
                'suggestions' => [],
                'has_exact_match' => true,
            ];
        }

        $referenceTitle = $sourceSalaryGrades->count() <= 1
            ? $this->positionReferenceTitle($pair['position'], $referenceCatalog)
            : null;
        if ($candidatePositions->isEmpty() && $referenceTitle !== null) {
            return [
                'position_id' => null,
                'position_title' => $referenceTitle,
                'status' => 'Official Reference Match',
                'reason' => 'The title is listed in the official Positions worksheet and can be added to the confirmed department',
                'confidence' => 100,
                'suggestions' => [],
                'has_exact_match' => false,
                'reference_title' => $referenceTitle,
            ];
        }

        $suggestions = $candidatePositions->isNotEmpty()
            ? $candidatePositions->map(fn (Position $position) => [
                'id' => $position->id,
                'title' => $position->title,
                'department' => $position->department?->name ?? 'Any department',
                'salary_grade' => $position->salary_grade,
                'score' => 100,
            ])->all()
            : $this->positionSuggestions($pair['position'], $departmentId, $positions);
        $ambiguousExactMatches = $candidatePositions->isNotEmpty();

        return [
            'position_id' => null,
            'position_title' => null,
            'status' => $suggestions ? 'Needs HR Confirmation' : 'Unmatched',
            'reason' => $ambiguousExactMatches
                ? 'Multiple existing positions match within the confirmed department'
                : ($sourceSalaryGrades->count() > 1
                    ? 'Workbook records show multiple salary grades for this position'
                    : ($suggestions
                    ? 'Possible matches are suggestions only and require HR confirmation'
                    : 'No existing position, approved alias, or official reference title matches this title in the confirmed department')),
            'confidence' => null,
            'suggestions' => $suggestions,
            'has_exact_match' => $ambiguousExactMatches,
        ];
    }

    private function positionReferenceTitle(string $title, array $referenceCatalog): ?string
    {
        $normalized = $this->normalizeMatchValue($title);

        return $normalized !== '' ? ($referenceCatalog[$normalized] ?? null) : null;
    }

    private function normalizedPositionReferenceCatalog(array $referenceTitles): array
    {
        $catalog = [];
        foreach ($referenceTitles as $reference) {
            if (! is_string($reference) || ! $this->isPositionReferenceTitle($reference)) {
                continue;
            }
            $normalized = $this->normalizeMatchValue($reference);
            $catalog[$normalized] ??= $reference;
        }

        return $catalog;
    }

    public function positionSuggestions(string $title, ?int $departmentId, ?Collection $positions = null): array
    {
        $normalized = $this->normalizeMatchValue($title);
        if ($normalized === '') {
            return [];
        }

        $positions ??= $this->activePositions();

        return $positions
            ->filter(fn (Position $position) => ! $departmentId
                || ! $position->department_id
                || (int) $position->department_id === $departmentId)
            ->map(function (Position $position) use ($normalized) {
                $score = collect([$position->title, ...($position->aliases ?? [])])
                    ->map(fn (string $candidate) => $this->matchScore($normalized, $candidate))
                    ->max() ?? 0;

                return [
                    'id' => $position->id,
                    'title' => $position->title,
                    'department' => $position->department?->name ?? 'Any department',
                    'salary_grade' => $position->salary_grade,
                    'score' => $score,
                ];
            })
            ->filter(fn (array $candidate) => $candidate['score'] >= 55)
            ->sortByDesc('score')
            ->take(3)
            ->values()
            ->all();
    }

    public function mappingSummary(EmployeeImport $import, ?int $officeColumn): array
    {
        if (! $officeColumn) {
            return [
                'departments' => collect(),
                'positions' => collect(),
                'department_map' => [],
                'position_map' => [],
                'department_suggestions' => [],
                'position_suggestions' => [],
                'department_matches' => [],
                'position_matches' => [],
                'reference_positions' => [],
            ];
        }

        $activePositions = $this->activePositions();
        $referenceCatalog = $this->normalizedPositionReferenceCatalog($import->position_reference_titles ?? []);
        $departmentMap = array_replace(
            $this->suggestedDepartmentMap($import, $officeColumn),
            $import->field_mappings['department_map'] ?? []
        );
        $positionMap = array_replace(
            $this->suggestedPositionMap($import, $officeColumn, $departmentMap, $activePositions, $referenceCatalog),
            $import->field_mappings['position_map'] ?? []
        );
        $approvedDepartments = $this->officialDepartments();
        $departmentMatches = $this->officeValues($import, $officeColumn)->mapWithKeys(function (string $office) use ($departmentMap, $approvedDepartments) {
            $key = hash('sha256', $office);
            $match = $this->departmentMatch($office, $approvedDepartments);
            $selectedId = filter_var($departmentMap[$key] ?? null, FILTER_VALIDATE_INT);
            if ($selectedId && $selectedId !== (int) ($match['department_id'] ?? 0)) {
                $selectedDepartment = $approvedDepartments->firstWhere('id', $selectedId);
                $match = [
                    'department_id' => $selectedDepartment?->id,
                    'department_name' => $selectedDepartment?->name,
                    'status' => 'Matched by Approved Alias',
                    'reason' => 'This source value has a saved HR-approved department mapping',
                    'confidence' => 100,
                    'suggestions' => [],
                    'has_exact_match' => false,
                ];
            }

            return [$key => $match];
        })->all();
        $departmentSuggestions = collect($departmentMatches)
            ->map(fn (array $match) => $match['suggestions'])
            ->all();
        $positionMatches = $this->positionPairs($import, $officeColumn)->mapWithKeys(function (array $pair) use ($import, $departmentMap, $positionMap, $activePositions, $referenceCatalog) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            $departmentId = filter_var($departmentMap[hash('sha256', $pair['office'])] ?? null, FILTER_VALIDATE_INT);
            $match = $this->positionMatch(
                $pair,
                $departmentId ?: null,
                $activePositions,
                $referenceCatalog
            );
            $selectedId = filter_var($positionMap[$key] ?? null, FILTER_VALIDATE_INT);
            if ($selectedId && $selectedId !== (int) ($match['position_id'] ?? 0)) {
                $selectedPosition = Position::query()->with('department')->find($selectedId);
                $match = [
                    'position_id' => $selectedPosition?->id,
                    'position_title' => $selectedPosition?->title,
                    'status' => 'Matched by Approved Alias',
                    'reason' => 'This source title has a saved HR-approved position mapping',
                    'confidence' => 100,
                    'suggestions' => [],
                    'has_exact_match' => false,
                ];
            }
            $createdPositionId = filter_var(
                $import->field_mappings['created_position_map'][$key] ?? null,
                FILTER_VALIDATE_INT
            );
            if ($selectedId && $createdPositionId === $selectedId) {
                $match['status'] = 'Automatically Matched – Newly Created HRIS Position';
                $match['reason'] = 'Created during this import and automatically applied to every affected employee record';
            }
            $match['affected_records'] = $pair['affected_records'];

            return [$key => $match];
        })->all();
        $positionSuggestions = collect($positionMatches)
            ->map(fn (array $match) => $match['suggestions'])
            ->all();
        $referencePositions = $this->positionPairs($import, $officeColumn)->mapWithKeys(function (array $pair) use ($referenceCatalog) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);

            return [$key => isset($referenceCatalog[$this->normalizeMatchValue($pair['position'])])];
        })->all();
        $officeRecords = $import->rows()->get()->groupBy(
            fn (EmployeeImportRow $row) => trim((string) ($this->cellByColumn($row->source_data, $officeColumn)['display_value'] ?? ''))
        );
        foreach ($departmentMatches as $key => &$match) {
            $office = $this->officeValues($import, $officeColumn)->first(
                fn (string $value) => hash('sha256', $value) === $key
            );
            $match['affected_records'] = $officeRecords->get($office, collect())
                ->map(function (EmployeeImportRow $row) {
                    $name = $this->parsedName($row);

                    return [
                        'source_row' => $row->source_row,
                        'name' => collect($name ?? [])->filter()->implode(' ') ?: 'Name needs review',
                    ];
                })->values()->all();
        }
        unset($match);

        return [
            'departments' => $this->officeValues($import, $officeColumn)
                ->filter(fn (string $office) => empty($departmentMap[hash('sha256', $office)]))
                ->values(),
            'positions' => $this->positionPairs($import, $officeColumn)
                ->filter(function (array $pair) use ($positionMap, $positionMatches) {
                    $key = hash('sha256', $pair['office']."\0".$pair['position']);

                    return empty($positionMap[$key])
                        && ($positionMatches[$key]['status'] ?? null) !== 'Official Reference Match';
                })
                ->values(),
            'department_map' => $departmentMap,
            'position_map' => $positionMap,
            'department_suggestions' => $departmentSuggestions,
            'position_suggestions' => $positionSuggestions,
            'department_matches' => $departmentMatches,
            'position_matches' => $positionMatches,
            'reference_positions' => $referencePositions,
        ];
    }

    private function activePositions(): Collection
    {
        return Position::query()->where('is_active', true)->with('department')->get();
    }

    public function officialDepartments(): Collection
    {
        $approvedNames = collect(config('hris.employee_import.official_departments', []))
            ->flatMap(fn (array $department) => [$department['name'], $department['code']])
            ->merge(array_keys(config('hris.employee_import.department_aliases', [])))
            ->map(fn (string $name) => $this->normalizeMatchValue($name))
            ->filter()
            ->unique()
            ->all();

        return Department::query()->where('is_active', true)->get()
            ->filter(function (Department $department) use ($approvedNames) {
                return ! empty($department->aliases)
                    || collect([$department->name, $department->code])
                        ->filter()
                        ->contains(fn (string $value) => in_array($this->normalizeMatchValue($value), $approvedNames, true));
            })
            ->sortBy('name')
            ->values();
    }

    private function isPositionInReference(EmployeeImport $import, string $title): bool
    {
        $normalized = $this->normalizeMatchValue($title);

        return $normalized !== ''
            && isset($this->normalizedPositionReferenceCatalog($import->position_reference_titles ?? [])[$normalized]);
    }

    public function importSummary(EmployeeImport $import, ?int $officeColumn): array
    {
        $rows = $import->rows()->get();
        $mappingSummary = $this->mappingSummary($import, $officeColumn);
        $mappedDepartments = count($mappingSummary['department_map']);
        $mappedPositions = count($mappingSummary['position_map']);
        $positionCount = $officeColumn ? $this->positionPairs($import, $officeColumn)->count() : 0;
        $statusCounts = $rows->countBy('status');
        $departmentMatchStatuses = collect($mappingSummary['department_matches'])
            ->pluck('status')
            ->countBy();
        $positionMatchStatuses = collect($mappingSummary['position_matches'])
            ->pluck('status')
            ->countBy();
        $readyNew = $rows->where('status', 'ready')->count();
        $readyUpdates = $rows->where('status', 'ready_to_update')->count();
        $validatedRows = $rows->whereIn('status', self::READY_ROW_STATUSES);
        $possibleDuplicates = $rows->filter(
            fn (EmployeeImportRow $row) => ! empty($row->review_data['classification']['possible_name_matches'])
        )->count();
        $unmatchedDepartmentCount = $departmentMatchStatuses->get('Unmatched', 0);
        $departmentConfirmationCount = $departmentMatchStatuses->get('Needs HR Confirmation', 0);
        $unmatchedPositionCount = $positionMatchStatuses->get('Unmatched', 0);
        $positionConfirmationCount = $positionMatchStatuses->get('Needs HR Confirmation', 0);
        $mappingBlockedRows = $rows->filter(fn (EmployeeImportRow $row) => $this->hasUnresolvedMappingIssues($row));
        $ambiguousPositionCount = collect($mappingSummary['position_matches'])
            ->filter(fn (array $match) => $match['status'] === 'Needs HR Confirmation'
                && (! empty($match['has_exact_match'])
                    || str_contains($match['reason'] ?? '', 'multiple salary grades')))
            ->count();
        $departmentBlockedRows = $rows->filter(fn (EmployeeImportRow $row) => collect($row->issues ?? [])
            ->contains(fn (string $issue) => str_contains($issue, 'has no approved department mapping.')
                || $issue === 'The mapped department is no longer active.'));
        $positionBlockedRows = $rows->filter(fn (EmployeeImportRow $row) => collect($row->issues ?? [])
            ->contains(fn (string $issue) => $issue === 'Latest position has no approved position mapping.'
                || $issue === 'The mapped position is no longer active or does not match the department.'));

        return [
            'total' => $import->row_count,
            'valid' => $validatedRows->count(),
            'importable' => $validatedRows->count(),
            'awaiting_mapping' => $mappingSummary['departments']->count() + $mappingSummary['positions']->count(),
            'awaiting_department_mapping' => $departmentBlockedRows->count(),
            'awaiting_position_mapping' => $positionBlockedRows->count(),
            'mapping_pending' => $import->field_mappings ? 0 : 1,
            'awaiting_validation' => $statusCounts->get('unvalidated', 0)
                + $statusCounts->get('existing_to_update', 0),
            'unresolved_records' => $rows->whereIn('status', [
                'unvalidated', 'existing_to_update', 'needs_review', 'failed',
            ])->count(),
            'remaining' => $rows->whereNotIn('status', ['imported', 'updated', 'duplicate'])->count(),
            'ready_new' => $readyNew,
            'ready_updates' => $readyUpdates,
            'new_records' => $rows->filter(fn (EmployeeImportRow $row) => ($row->review_data['classification']['action'] ?? 'create') === 'create'
                && ! in_array($row->status, ['duplicate', 'failed'], true))->count(),
            'accounts_to_create' => $rows->filter(fn (EmployeeImportRow $row) => ($row->review_data['classification']['account_action'] ?? 'create') === 'create'
                && in_array($row->status, self::READY_ROW_STATUSES, true))->count(),
            'existing_to_update' => $rows->filter(fn (EmployeeImportRow $row) => ($row->review_data['classification']['action'] ?? null) === 'update'
                && ! in_array($row->status, ['duplicate', 'failed'], true))->count(),
            'imported' => $rows->where('status', 'imported')->count(),
            'updated' => $rows->where('status', 'updated')->count(),
            'accounts_created' => (int) $import->accounts_created_count,
            'pending_setup_links' => $rows->filter(
                fn (EmployeeImportRow $row) => in_array($row->status, ['imported', 'updated'], true)
                    && ($row->review_data['import_result']['account_created'] ?? false)
                    && empty($row->review_data['import_result']['setup_link_sent_at'])
                    && empty($row->review_data['import_result']['setup_link_status'])
            )->count(),
            'failed' => $rows->where('status', 'failed')->count(),
            'possible_duplicates' => $possibleDuplicates,
            'mapping_blocked_records' => $mappingBlockedRows->count(),
            'office_heads' => $rows->filter(fn (EmployeeImportRow $row) => ($row->review_data['classification']['role'] ?? null) === 'office_head')->count(),
            'employees' => $rows->filter(fn (EmployeeImportRow $row) => ($row->review_data['classification']['role'] ?? 'employee') === 'employee'
                && $row->status !== 'duplicate')->count(),
            'departments' => $officeColumn ? $this->officeValues($import, $officeColumn)->count() : 0,
            'positions_detected' => $positionCount,
            'matched_positions' => $mappedPositions,
            'position_reference_count' => (int) $import->position_reference_count,
            'reference_position_matches' => count(array_filter($mappingSummary['reference_positions'])),
            'plantilla_items' => (int) $import->plantilla_item_count,
            'vacancies' => (int) $import->vacant_count,
            'excluded_offices' => (int) $import->excluded_office_count,
            'unmatched_departments' => $mappingSummary['departments'],
            'unmatched_positions' => $mappingSummary['positions'],
            'unmatched_department_count' => $unmatchedDepartmentCount,
            'department_confirmation_count' => $departmentConfirmationCount,
            'unmatched_position_count' => $unmatchedPositionCount,
            'position_confirmation_count' => $positionConfirmationCount,
            'ambiguous_position_count' => $ambiguousPositionCount,
            'duplicates' => $rows->where('status', 'duplicate')->count(),
            'corrections' => $rows->filter(fn (EmployeeImportRow $row) => $this->needsEmployeeCorrection($row))->count(),
            'matched_departments' => $mappedDepartments,
        ];
    }

    public function hasUnresolvedMappingIssues(EmployeeImportRow $row): bool
    {
        return $row->status === 'needs_review'
            && collect($row->issues ?? [])
                ->contains(fn (string $issue) => $this->isMappingIssue($issue));
    }

    public function needsEmployeeCorrection(EmployeeImportRow $row): bool
    {
        return $row->status === 'needs_review'
            && collect($row->issues ?? [])
                ->contains(fn (string $issue) => ! $this->isMappingIssue($issue));
    }

    private function isMappingIssue(string $issue): bool
    {
        return str_contains($issue, 'has no approved department mapping.')
            || $issue === 'The mapped department is no longer active.'
            || $issue === 'Latest position has no approved position mapping.'
            || $issue === 'The mapped position is no longer active or does not match the department.';
    }

    public function saveMapping(EmployeeImport $import, array $mapping, int $userId): array
    {
        return DB::transaction(fn () => $this->persistMapping($import, $mapping, $userId));
    }

    private function persistMapping(EmployeeImport $import, array $mapping, int $userId): array
    {
        if (! in_array($import->status, ['mapping_required', 'ready_for_import', 'partially_ready', 'completed_with_errors', 'failed', 'pending_review', 'mapping_approved', 'review_required'], true)) {
            throw ValidationException::withMessages(['mapping' => 'Mappings can only be changed while an import is awaiting review.']);
        }
        $officeColumn = (int) ($mapping['office_source_column'] ?? 0);
        $officeColumns = collect($this->availableOfficeColumns($import))->pluck('index')->all();
        if (! in_array($officeColumn, $officeColumns, true)) {
            throw ValidationException::withMessages(['office_source_column' => 'Choose one of the source OFFICE columns.']);
        }
        $employmentStatus = $mapping['employment_status'] ?? 'Active';
        if (! in_array($employmentStatus, ['Active', 'On Leave', 'Separated', 'Retired'], true)) {
            throw ValidationException::withMessages(['employment_status' => 'Select a supported employment status.']);
        }
        $departments = array_replace(
            $this->suggestedDepartmentMap($import, $officeColumn),
            $mapping['department_map'] ?? []
        );
        $approvedDepartmentIds = $this->officialDepartments()->modelKeys();
        foreach ($this->officeValues($import, $officeColumn) as $value) {
            $key = hash('sha256', $value);
            $selectedDepartment = $departments[$key] ?? null;
            if ($selectedDepartment === null || $selectedDepartment === '') {
                continue;
            }
            if ($selectedDepartment === 'create') {
                $department = $this->createDepartmentFromSource(
                    $value,
                    $mapping['new_departments'][$key] ?? []
                );
                $departments[$key] = $department->id;
                $approvedDepartmentIds[] = $department->id;

                foreach ($this->officeValues($import, $officeColumn) as $equivalentOffice) {
                    if ($this->normalizeMatchValue($equivalentOffice) === $this->normalizeMatchValue($value)) {
                        $departments[hash('sha256', $equivalentOffice)] = $department->id;
                    }
                }

                continue;
            }
            $departmentId = filter_var($selectedDepartment, FILTER_VALIDATE_INT);
            $department = $departmentId && in_array($departmentId, $approvedDepartmentIds, true)
                ? Department::query()->where('is_active', true)->find($departmentId)
                : null;
            if (! $department) {
                throw ValidationException::withMessages([
                    "department_map.{$key}" => 'Select an approved active department, or explicitly authorize creating a new department in this import.',
                ]);
            }
        }

        $positions = array_replace(
            $this->suggestedPositionMap($import, $officeColumn, $departments),
            $mapping['position_map'] ?? []
        );
        $referenceCatalog = $this->normalizedPositionReferenceCatalog($import->position_reference_titles ?? []);
        $createdPositions = [];
        $reusedPositions = [];
        $createdPositionMap = $import->field_mappings['created_position_map'] ?? [];
        foreach ($this->positionPairs($import, $officeColumn) as $pair) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            if (filter_var($mapping['create_position'][$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                if (! filter_var($departments[hash('sha256', $pair['office'])] ?? null, FILTER_VALIDATE_INT)) {
                    throw ValidationException::withMessages([
                        "position_map.{$key}" => 'Confirm an active department before creating a position.',
                    ]);
                }
                $positions[$key] = 'create';
            }
        }
        foreach ($this->positionPairs($import, $officeColumn) as $pair) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            if (! empty($positions[$key])) {
                continue;
            }
            $departmentId = filter_var($departments[hash('sha256', $pair['office'])] ?? null, FILTER_VALIDATE_INT);
            if (! $departmentId) {
                continue;
            }
            $match = $this->positionMatch(
                $pair,
                $departmentId,
                $this->activePositions(),
                $referenceCatalog
            );
            if ($match['status'] !== 'Official Reference Match') {
                continue;
            }
            $position = $this->createPositionFromSource(
                $pair['position'],
                $departmentId,
                [
                    'title' => $match['position_title'],
                    'salary_grade' => $pair['salary_grade'],
                ],
                $key
            );
            $positions[$key] = $position->id;
            if ($position->wasRecentlyCreated) {
                $createdPositionMap[$key] = $position->id;
                $createdPositions[] = [
                    'id' => $position->id,
                    'title' => $position->title,
                    'department' => $position->department?->name,
                ];
            }
        }
        foreach ($this->positionPairs($import, $officeColumn) as $pair) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            $departmentId = filter_var($departments[hash('sha256', $pair['office'])] ?? null, FILTER_VALIDATE_INT);
            if (! $departmentId) {
                if (($positions[$key] ?? null) === 'create') {
                    throw ValidationException::withMessages([
                        "position_map.{$key}" => 'Confirm an active department before creating a position.',
                    ]);
                }

                continue;
            }
            $selectedPosition = $positions[$key] ?? null;
            if ($selectedPosition === null || $selectedPosition === '') {
                continue;
            }
            if ($selectedPosition === 'create') {
                $newPosition = $mapping['new_positions'][$key] ?? [];
                $referenceTitle = $this->positionReferenceTitle($pair['position'], $referenceCatalog);
                if ($referenceTitle !== null) {
                    $newPosition['title'] = $referenceTitle;
                    $newPosition['salary_grade'] = $newPosition['salary_grade'] ?? $pair['salary_grade'];
                }
                $position = $this->createPositionFromSource(
                    $pair['position'],
                    $departmentId,
                    $newPosition,
                    $key
                );
                $positions[$key] = $position->id;
                if ($position->wasRecentlyCreated) {
                    $createdPositionMap[$key] = $position->id;
                    $createdPositions[] = [
                        'id' => $position->id,
                        'title' => $position->title,
                        'department' => $position->department?->name,
                    ];
                } else {
                    $reusedPositions[] = [
                        'id' => $position->id,
                        'title' => $position->title,
                        'department' => $position->department?->name,
                    ];
                }

                continue;
            }
            $positionId = filter_var($positions[$key] ?? null, FILTER_VALIDATE_INT);
            $position = $positionId ? Position::query()->where('is_active', true)->find($positionId) : null;
            if (! $position || ($position->department_id && (int) $position->department_id !== $departmentId)) {
                throw ValidationException::withMessages([
                    "position_map.{$key}" => "Select an existing position or confirm creating \"{$pair['position']}\" in its mapped department.",
                ]);
            }
        }

        $createdPositionMap = array_filter(
            $createdPositionMap,
            fn ($positionId, $key) => (int) ($positions[$key] ?? 0) === (int) $positionId,
            ARRAY_FILTER_USE_BOTH
        );
        $approvedMapping = [
            'source_format' => $this->normalizeMatchValue((string) $import->worksheet_name) === 'PLANTILLA'
                ? 'plantilla'
                : 'legacy',
            'office_source_column' => $officeColumn,
            'employment_status' => $employmentStatus,
            'date_source_column' => null,
            'date_target' => 'source_only',
            'department_map' => array_filter(
                array_intersect_key($departments, array_fill_keys(
                    $this->officeValues($import, $officeColumn)->map(fn (string $value) => hash('sha256', $value))->all(),
                    true
                )),
                fn ($departmentId) => filter_var($departmentId, FILTER_VALIDATE_INT) !== false
            ),
            'position_map' => array_filter(
                array_intersect_key($positions, array_fill_keys(
                    $this->positionPairs($import, $officeColumn)->map(fn (array $pair) => hash('sha256', $pair['office']."\0".$pair['position']))->all(),
                    true
                )),
                fn ($positionId) => filter_var($positionId, FILTER_VALIDATE_INT) !== false
            ),
            'created_position_map' => $createdPositionMap,
        ];

        DB::transaction(function () use ($import, $approvedMapping, $userId) {
            $locked = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($locked->status, ['mapping_required', 'ready_for_import', 'partially_ready', 'completed_with_errors', 'failed', 'pending_review', 'mapping_approved', 'review_required'], true)) {
                throw ValidationException::withMessages(['mapping' => 'This import is no longer available for mapping review.']);
            }
            $this->persistApprovedAliases($import, $approvedMapping);
            $locked->update([
                'field_mappings' => $approvedMapping,
                'mapping_approved_by' => $userId,
                'mapping_approved_at' => now(),
                'status' => 'ready_for_import',
            ]);
            EmployeeImportMappingEvent::create([
                'employee_import_id' => $locked->id,
                'changed_by' => $userId,
                'mapping' => $approvedMapping,
            ]);
            $this->refreshPreview($locked, $userId);
        });

        return [
            'created_positions' => $createdPositions,
            'reused_positions' => $reusedPositions,
        ];
    }

    private function persistApprovedAliases(EmployeeImport $import, array $mapping): void
    {
        $officeColumn = (int) $mapping['office_source_column'];
        foreach ($this->officeValues($import, $officeColumn) as $office) {
            $departmentId = filter_var($mapping['department_map'][hash('sha256', $office)] ?? null, FILTER_VALIDATE_INT);
            if (! $departmentId) {
                continue;
            }
            $department = Department::query()->lockForUpdate()->findOrFail($departmentId);
            $normalized = $this->normalizeMatchValue($office);
            if ($normalized === $this->normalizeMatchValue($department->name)
                || $normalized === $this->normalizeMatchValue($department->code ?? '')) {
                continue;
            }

            $configuredTarget = collect(config('hris.employee_import.department_aliases', []))
                ->first(fn (string $target, string $alias) => $this->normalizeMatchValue($alias) === $normalized);
            $configuredDepartment = $configuredTarget
                ? Department::query()->get()->first(fn (Department $candidate) => $this->normalizeMatchValue($candidate->name) === $this->normalizeMatchValue($configuredTarget)
                    || $this->normalizeMatchValue($candidate->code ?? '') === $this->normalizeMatchValue($configuredTarget))
                : null;
            $conflict = ($configuredDepartment && (int) $configuredDepartment->id !== (int) $department->id)
                || Department::query()->where('id', '!=', $department->id)->get()->contains(
                    fn (Department $candidate) => $this->normalizeMatchValue($candidate->name) === $normalized
                        || $this->normalizeMatchValue($candidate->code ?? '') === $normalized
                        || collect($candidate->aliases ?? [])->contains(
                            fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized
                        )
                );
            if ($conflict) {
                throw ValidationException::withMessages([
                    'department_map' => "The office value \"{$office}\" conflicts with another department name or approved alias.",
                ]);
            }

            $aliases = $department->aliases ?? [];
            if (! collect($aliases)->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized)) {
                $aliases[] = $office;
                $department->update(['aliases' => array_values($aliases)]);
            }
        }

        foreach ($this->positionPairs($import, $officeColumn) as $pair) {
            $key = hash('sha256', $pair['office']."\0".$pair['position']);
            $positionId = filter_var($mapping['position_map'][$key] ?? null, FILTER_VALIDATE_INT);
            if (! $positionId) {
                continue;
            }
            $position = Position::query()->lockForUpdate()->findOrFail($positionId);
            $normalized = $this->normalizeMatchValue($pair['position']);
            if ($normalized === $this->normalizeMatchValue($position->title)) {
                continue;
            }

            $departmentId = filter_var($mapping['department_map'][hash('sha256', $pair['office'])] ?? null, FILTER_VALIDATE_INT);
            if (! $departmentId) {
                continue;
            }
            $conflict = Position::query()
                ->where('id', '!=', $position->id)
                ->where(function ($query) use ($departmentId, $position) {
                    $query->whereNull('department_id')
                        ->orWhere('department_id', $departmentId);
                    if ($position->department_id === null) {
                        $query->orWhereNotNull('department_id');
                    }
                })
                ->get()
                ->contains(fn (Position $candidate) => $this->normalizeMatchValue($candidate->title) === $normalized
                    || collect($candidate->aliases ?? [])->contains(
                        fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized
                    ));
            if ($conflict) {
                throw ValidationException::withMessages([
                    'position_map' => "The source title \"{$pair['position']}\" conflicts with another position title or approved alias.",
                ]);
            }

            $aliases = $position->aliases ?? [];
            if (! collect($aliases)->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalized)) {
                $aliases[] = $pair['position'];
                $position->update(['aliases' => array_values($aliases)]);
            }
        }
    }

    public function confirm(EmployeeImport $import, int $userId): EmployeeImport
    {
        $import = EmployeeImport::query()->findOrFail($import->getKey());
        if (! in_array($import->status, ['ready_for_import', 'partially_ready', 'completed_with_errors', 'failed', 'importing', 'mapping_approved', 'review_required'], true) || ! $import->field_mappings) {
            throw ValidationException::withMessages(['mapping' => 'Select an OFFICE column, map its values and positions, and save the mappings before importing.']);
        }
        if ($import->status === 'failed' && $import->rows()->where('status', 'failed')->exists()) {
            throw ValidationException::withMessages(['import' => 'Correct or remap the records that failed before retrying this batch.']);
        }

        $preflight = DB::transaction(function () use ($import) {
            $lockedImport = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            $resuming = $lockedImport->status === 'importing';
            if ((! $resuming && ! in_array($lockedImport->status, ['ready_for_import', 'partially_ready', 'completed_with_errors', 'failed', 'mapping_approved', 'review_required'], true))
                || ! $lockedImport->field_mappings || ! $lockedImport->previewed_at) {
                throw ValidationException::withMessages(['import' => 'This workbook has already been processed or changed.']);
            }

            if (! $resuming) {
                $hasReadyRows = $this->readyImportRowsCount($lockedImport) > 0;
                if (! $hasReadyRows && ! $this->containsOnlyDuplicateRows($lockedImport)) {
                    return ['eligible' => false, 'import' => $lockedImport->fresh()];
                }

                $lockedImport->update(['status' => 'importing']);
            }

            return ['eligible' => true, 'import' => $lockedImport];
        });
        if (! $preflight['eligible']) {
            throw ValidationException::withMessages([
                'import' => 'No validated employee records are ready to import. Resolve outstanding mappings or corrections, then save and validate again.',
            ]);
        }
        $lockedForImport = $preflight['import'];

        try {
            return $this->importReadyRows($lockedForImport, $userId);
        } catch (\Throwable $exception) {
            EmployeeImport::query()->whereKey($lockedForImport->id)
                ->where('status', 'importing')
                ->update(['status' => 'failed']);
            throw $exception;
        }
    }

    private function importReadyRows(EmployeeImport $import, int $userId): EmployeeImport
    {
        $lockedImport = EmployeeImport::query()->findOrFail($import->id);
        if ($lockedImport->status !== 'importing') {
            return $lockedImport;
        }
        $mapping = $lockedImport->field_mappings;
        $plantillaItemOwners = ($mapping['source_format'] ?? null) === 'plantilla'
            ? EmployeeServiceProfile::query()
                ->whereNotNull('plantilla_item_no')
                ->lockForUpdate()
                ->get(['employee_id', 'plantilla_item_no'])
                ->groupBy(fn (EmployeeServiceProfile $profile) => $this->normalizeMatchValue((string) $profile->plantilla_item_no))
                ->map(fn (Collection $profiles) => $profiles->pluck('employee_id')->all())
                ->all()
            : [];
        $passwordHash = null;
        $rows = $lockedImport->rows()
            ->whereIn('status', ['ready', 'ready_to_update', 'pending_review'])
            ->orderBy('id')
            ->limit(self::IMPORT_BATCH_SIZE)
            ->get();
        foreach ($rows as $queuedRow) {
            $rowId = $queuedRow->id;
            $itemOwnerToAdd = null;
            try {
                DB::transaction(function () use (
                    $rowId,
                    $lockedImport,
                    $mapping,
                    &$plantillaItemOwners,
                    &$passwordHash,
                    &$itemOwnerToAdd
                ): void {
                    $row = EmployeeImportRow::query()->lockForUpdate()->findOrFail($rowId);
                    if (! in_array($row->status, ['ready', 'ready_to_update', 'pending_review'], true)) {
                        return;
                    }
                    $prepared = $this->prepareEmployeeData($row, $mapping);
                    if ($prepared['issues']) {
                        $row->update(['status' => 'needs_review', 'issues' => $prepared['issues']]);

                        return;
                    }
                    $employeeQuery = Employee::withTrashed()->with('user')->lockForUpdate();
                    $employee = $prepared['existing_employee_id']
                        ? $employeeQuery->find($prepared['existing_employee_id'])
                        : $employeeQuery->whereRaw('LOWER(employee_no) = ?', [mb_strtolower((string) $prepared['employee']['employee_no'])])->first();
                    if ($employee?->trashed()) {
                        $row->update([
                            'status' => 'duplicate',
                            'issues' => ['An archived employee already uses this employee number. The archived record was not changed.'],
                        ]);

                        return;
                    }
                    if ($employee?->user?->role === 'admin') {
                        $row->update([
                            'status' => 'duplicate',
                            'issues' => ['This employee number belongs to a protected Admin account. The employee and account were not changed.'],
                        ]);

                        return;
                    }
                    $itemNumber = trim((string) ($prepared['profile']['plantilla_item_no'] ?? ''));
                    $itemNumberKey = $this->normalizeMatchValue($itemNumber);
                    $itemOwners = $itemNumberKey !== '' ? ($plantillaItemOwners[$itemNumberKey] ?? []) : [];
                    if ($itemOwners && ! in_array($employee?->id, $itemOwners, true)) {
                        $row->update([
                            'status' => 'needs_review',
                            'issues' => ['This plantilla item number is already assigned to another employee. Review the current assignment before importing.'],
                        ]);

                        return;
                    }

                    $isExisting = $employee !== null;
                    $previousDepartmentId = $employee?->department_id;
                    $existingUser = $employee?->user;
                    $effectiveRole = $existingUser?->role ?? $prepared['role'];
                    $targetDepartment = null;
                    if ($effectiveRole === 'office_head') {
                        $targetDepartment = Department::query()
                            ->whereKey($prepared['employee']['department_id'])
                            ->lockForUpdate()
                            ->firstOrFail();
                        if ($targetDepartment->head_user_id
                            && (int) $targetDepartment->head_user_id !== (int) ($existingUser?->id ?? 0)) {
                            $row->update([
                                'status' => 'needs_review',
                                'issues' => ['This department has another assigned Office Head. Resolve the assignment before importing.'],
                            ]);

                            return;
                        }
                    }
                    $employeeData = $prepared['employee'];
                    if (! $prepared['employment_status_from_source'] && $isExisting) {
                        unset($employeeData['employment_status']);
                    }
                    if ($isExisting) {
                        unset($employeeData['employee_no']);
                        $employeeData = array_filter($employeeData, fn ($value) => $value !== null && $value !== '');
                        $employee->update($employeeData);
                        $employee->refresh();
                    } else {
                        if (! $employeeData['employee_no']) {
                            $employeeData['employee_no'] = $this->generateInternalEmployeeNumber();
                        }
                        $employee = Employee::create($employeeData);
                    }

                    $user = $employee->user;
                    $accountCreated = false;
                    if (! $user) {
                        if ($passwordHash === null) {
                            $temporaryPassword = config('hris.employee_import.default_password');
                            if (! is_string($temporaryPassword) || $temporaryPassword === '') {
                                throw new \LogicException('The employee import default password is not configured.');
                            }
                            $passwordHash = Hash::make($temporaryPassword);
                        }
                        $user = User::create([
                            'name' => $employee->full_name,
                            'username' => $this->usernameGenerator->generateForNameParts(
                                $employee->first_name,
                                $employee->middle_name,
                                $employee->last_name,
                                $employee->suffix,
                                $employee->employee_no
                            ),
                            'email' => $prepared['employee']['email'],
                            'password' => $passwordHash,
                            'must_change_password' => true,
                            'account_setup_completed' => false,
                            'role' => $prepared['role'],
                            'is_active' => true,
                            'can_final_approve' => false,
                        ]);
                        $employee->update(['user_id' => $user->id]);
                        $accountCreated = true;
                    } else {
                        $user->update(['name' => $employee->full_name]);
                    }

                    if ($user->role === 'office_head') {
                        if ($previousDepartmentId && (int) $previousDepartmentId !== (int) $employee->department_id) {
                            Department::query()->whereKey($previousDepartmentId)
                                ->where('head_user_id', $user->id)
                                ->update(['head_user_id' => null]);
                        }
                        $targetDepartment?->update(['head_user_id' => $user->id]);
                    }

                    $prepared['profile']['employee_id'] = $employee->id;
                    $prepared['profile']['employee_import_id'] = $lockedImport->id;
                    $prepared['profile']['source_row'] = $row->source_row;
                    if ($isExisting) {
                        $profileData = array_filter($prepared['profile'], fn ($value) => $value !== null && $value !== '');
                        EmployeeServiceProfile::updateOrCreate(
                            ['employee_id' => $employee->id],
                            $profileData
                        );
                    } else {
                        EmployeeServiceProfile::create($prepared['profile']);
                    }

                    foreach ($prepared['history'] as $history) {
                        $historyData = [
                            ...$history,
                            'employee_id' => $employee->id,
                            'employee_import_id' => $lockedImport->id,
                            'source_row' => $row->source_row,
                        ];
                        $existingHistory = $isExisting
                            ? EmployeeEmploymentHistory::query()
                                ->where('employee_id', $employee->id)
                                ->where('sequence', $history['sequence'])
                                ->first()
                            : null;
                        if ($existingHistory && ! $existingHistory->employee_import_id) {
                            continue;
                        }
                        if ($existingHistory) {
                            $existingHistory->update($historyData);
                        } else {
                            EmployeeEmploymentHistory::create($historyData);
                        }
                    }

                    $resultData = array_replace($row->review_data ?? [], [
                        'import_result' => [
                            'action' => $isExisting ? 'updated' : 'created',
                            'account_created' => $accountCreated,
                        ],
                    ]);
                    $row->update([
                        'employee_id' => $employee->id,
                        'review_data' => $resultData,
                        'status' => $isExisting ? 'updated' : 'imported',
                        'issues' => null,
                    ]);
                    if ($itemNumberKey !== '') {
                        $itemOwnerToAdd = [$itemNumberKey, $employee->id];
                    }
                });

                if ($itemOwnerToAdd) {
                    [$itemNumberKey, $employeeId] = $itemOwnerToAdd;
                    $plantillaItemOwners[$itemNumberKey] = array_values(array_unique([
                        ...($plantillaItemOwners[$itemNumberKey] ?? []),
                        $employeeId,
                    ]));
                }
            } catch (\Throwable $exception) {
                report($exception);
                EmployeeImportRow::query()
                    ->whereKey($rowId)
                    ->whereIn('status', ['ready', 'ready_to_update', 'pending_review'])
                    ->update([
                        'status' => 'failed',
                        'issues' => ['This employee could not be saved because a database or account constraint rejected the row. No employee, account, profile, history, or import-row link was committed. Review the row and retry.'],
                    ]);
            }
        }

        $needsReview = $lockedImport->rows()->where('status', 'needs_review')->count();
        $failed = $lockedImport->rows()->where('status', 'failed')->count();
        $skipped = $lockedImport->rows()->where('status', 'duplicate')->count();
        $remaining = $lockedImport->rows()->whereIn('status', ['ready', 'ready_to_update', 'pending_review'])->exists();
        $importedCount = $lockedImport->rows()->where('status', 'imported')->count();
        $updatedCount = $lockedImport->rows()->where('status', 'updated')->count();
        $accountsCreatedCount = $lockedImport->rows()->whereIn('status', ['imported', 'updated'])
            ->get()
            ->filter(fn (EmployeeImportRow $row) => $row->review_data['import_result']['account_created'] ?? false)
            ->count();
        $lockedImport->update([
            'status' => $remaining ? 'importing' : (($needsReview || $failed) ? 'completed_with_errors' : 'completed'),
            'confirmed_by' => $remaining ? null : $userId,
            'confirmed_at' => $remaining ? null : now(),
            'imported_count' => $importedCount,
            'updated_count' => $updatedCount,
            'accounts_created_count' => $accountsCreatedCount,
            'skipped_count' => $skipped,
            'review_count' => $needsReview + $failed,
        ]);

        return $lockedImport->fresh();
    }

    private function readyImportRowsCount(EmployeeImport $import): int
    {
        return $import->rows()->whereIn('status', self::READY_ROW_STATUSES)->count();
    }

    private function containsOnlyDuplicateRows(EmployeeImport $import): bool
    {
        return $import->rows()->exists()
            && ! $import->rows()->where('status', '!=', 'duplicate')->exists();
    }

    public function saveRowReview(EmployeeImport $import, EmployeeImportRow $row, array $correction, int $userId): void
    {
        if ($row->employee_import_id !== $import->id || $row->status === 'duplicate' || $row->status === 'imported') {
            throw ValidationException::withMessages(['row' => 'This source row cannot be edited in its current state.']);
        }

        DB::transaction(function () use ($import, $row, $correction, $userId) {
            $lockedImport = EmployeeImport::query()->lockForUpdate()->findOrFail($import->id);
            if (! in_array($lockedImport->status, ['mapping_required', 'ready_for_import', 'partially_ready', 'completed_with_errors', 'failed', 'pending_review', 'mapping_approved', 'review_required'], true)) {
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
                'review_data' => array_replace($lockedRow->review_data ?? [], array_filter([
                    'name' => $correction['name'] ?? null,
                    'employee' => array_filter($correction['employee'] ?? [], fn ($value) => $value !== null && $value !== ''),
                    'profile' => array_filter($correction['profile'] ?? [], fn ($value) => $value !== null && $value !== ''),
                ], fn ($value) => $value !== null && $value !== [])),
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
                'status' => 'ready',
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

        $rows = $import->rows()->whereNotIn('status', ['duplicate', 'imported', 'updated'])->orderBy('source_row')->lockForUpdate()->get();
        $preparedRows = $rows->mapWithKeys(fn (EmployeeImportRow $row) => [$row->id => $this->prepareEmployeeData($row, $mapping)]);
        $emailCounts = $preparedRows->pluck('employee.email')->filter()->countBy();
        $needsReview = 0;
        foreach ($rows as $row) {
            $prepared = $preparedRows[$row->id];
            $issues = $prepared['issues'];
            $email = $prepared['employee']['email'];
            if ($email && $emailCounts[$email] > 1) {
                $issues[] = 'This email address appears more than once in the workbook.';
            }
            $needsReview += $issues ? 1 : 0;
            $row->update(['review_data' => array_replace($row->review_data ?? [], [
                'classification' => [
                    'action' => $prepared['existing_employee_id'] ? 'update' : 'create',
                    'account_action' => $prepared['account_action'],
                    'role' => $prepared['role'],
                    'department' => $prepared['department_name'],
                    'position' => $prepared['position_title'],
                    'possible_name_matches' => $prepared['possible_name_matches'],
                    'identity_type' => $prepared['identity_type'],
                    'plantilla_item_no' => $prepared['plantilla_item_no'],
                ],
            ])]);
            $row->update([
                'status' => $issues ? 'needs_review' : ($prepared['existing_employee_id'] ? 'ready_to_update' : 'ready'),
                'issues' => array_values(array_unique($issues)) ?: null,
            ]);
        }
        $freshRows = $import->rows()->get();
        $readyCount = $freshRows->whereIn('status', self::READY_ROW_STATUSES)->count();
        $mappingIssuesRemain = $freshRows->contains(
            fn (EmployeeImportRow $row) => $this->hasUnresolvedMappingIssues($row)
        );
        $unresolvedRowsRemain = $freshRows->contains(
            fn (EmployeeImportRow $row) => ! in_array($row->status, [
                ...self::READY_ROW_STATUSES, 'duplicate', 'imported', 'updated',
            ], true)
        );
        $nextStatus = $readyCount > 0
            ? ($unresolvedRowsRemain ? 'partially_ready' : 'ready_for_import')
            : ($mappingIssuesRemain ? 'mapping_required' : 'review_required');

        $import->update([
            'previewed_by' => $userId,
            'previewed_at' => now(),
            'review_count' => $needsReview,
            'status' => $nextStatus,
        ]);
    }

    public function parsedName(EmployeeImportRow $row): ?array
    {
        $firstName = $this->displayByHeader($row->source_data, 'FIRST NAME');
        $lastName = $this->displayByHeader($row->source_data, 'LAST NAME');
        if ($firstName !== null || $lastName !== null) {
            return [
                'first_name' => trim((string) $firstName),
                'middle_name' => trim((string) ($this->displayByHeader($row->source_data, 'MIDDLE NAME') ?? '')) ?: null,
                'last_name' => trim((string) $lastName),
                'suffix' => null,
            ];
        }

        return $this->parseName(trim((string) ($this->cellByHeader($row->source_data, 'NAME')['display_value'] ?? '')));
    }

    public function correctionDefaults(EmployeeImportRow $row): array
    {
        return [
            'name' => $this->parsedName($row) ?? [],
            'employee' => [
                'employee_no' => $this->displayByHeader($row->source_data, 'NO.'),
                'email' => $this->emailFromSource($row->source_data),
                'sex' => $this->displayByHeader($row->source_data, 'GENDER'),
                'date_of_birth' => $this->dateValue($this->cellByHeader($row->source_data, 'BDATE')),
                'employment_type' => $this->displayByHeader(
                    $row->source_data,
                    $this->cellByHeader($row->source_data, 'STATUS') ? 'STATUS' : 'NATURE OF APPOINTMENT'
                ),
            ],
            'profile' => [
                'position_level' => $this->displayByHeader($row->source_data, 'POSITION LEVEL'),
                'source_classification' => $this->displayByHeader(
                    $row->source_data,
                    $this->cellByHeader($row->source_data, 'STATUS') ? 'STATUS' : 'CAT'
                ),
            ],
        ];
    }

    private function prepareEmployeeData(EmployeeImportRow $row, array $mapping): array
    {
        $data = $row->source_data;
        $format = $mapping['source_format'] ?? 'legacy';
        $identity = $row->review_data['identity'] ?? [];
        $reviewEmployee = $row->review_data['employee'] ?? [];
        $reviewProfile = $row->review_data['profile'] ?? [];
        $officeCell = $this->cellByColumn($data, (int) $mapping['office_source_column']);
        $officeValue = trim((string) ($officeCell['display_value'] ?? ''));
        $positionColumn = $this->headerColumnFromData($data, 'LATEST POSITION');
        $positionValue = trim((string) ($this->cellByColumn($data, $positionColumn)['display_value'] ?? ''));
        $departmentId = $mapping['department_map'][hash('sha256', $officeValue)] ?? null;
        $positionId = $mapping['position_map'][hash('sha256', $officeValue."\0".$positionValue)] ?? null;
        $name = $row->review_data['name'] ?? ($format === 'plantilla'
            ? [
                'first_name' => trim((string) ($this->displayByHeader($data, 'FIRST NAME') ?? '')),
                'middle_name' => trim((string) ($this->displayByHeader($data, 'MIDDLE NAME') ?? '')) ?: null,
                'last_name' => trim((string) ($this->displayByHeader($data, 'LAST NAME') ?? '')),
                'suffix' => null,
            ]
            : $this->parseName(trim((string) ($this->cellByHeader($data, 'NAME')['display_value'] ?? ''))));
        $issues = [];
        if (! empty($identity['error'])) {
            $issues[] = $identity['error'];
        }
        $requiredHeaders = $format === 'plantilla'
            ? ['LAST NAME', 'FIRST NAME', 'ITEM NUMBER', 'BDATE', 'GENDER', 'STATUS', 'LATEST POSITION', 'OFFICE']
            : ['NO.', 'NAME', 'BDATE', 'CAT', 'GENDER', 'NATURE OF APPOINTMENT', 'LATEST POSITION'];
        foreach ($data as $cell) {
            if ($cell['formula_error'] && in_array($cell['canonical_header'] ?? $cell['header'], $requiredHeaders, true)) {
                $issues[] = 'A required employee value contains a formula that could not be calculated.';
                break;
            }
        }
        if (! $name) {
            $issues[] = 'Employee name is missing or cannot be parsed unambiguously.';
        }
        if ($name && collect(['first_name', 'last_name'])->contains(fn (string $field) => trim((string) ($name[$field] ?? '')) === ''
            || mb_strlen((string) $name[$field]) > 255)) {
            $issues[] = 'Employee first or last name is missing or exceeds the supported length.';
        }
        if ($name && mb_strlen((string) ($name['middle_name'] ?? '')) > 255) {
            $issues[] = 'Employee middle name exceeds the supported length.';
        }
        if ($name && mb_strlen((string) ($name['suffix'] ?? '')) > 20) {
            $issues[] = 'Employee suffix exceeds the supported length.';
        }
        if ($officeValue === '' || ! $departmentId) {
            $issues[] = $officeValue === ''
                ? 'Office value is blank.'
                : "Office value \"{$officeValue}\" has no approved department mapping.";
        } elseif (! Department::query()->whereKey($departmentId)->where('is_active', true)->exists()) {
            $issues[] = 'The mapped department is no longer active.';
        }
        if ($positionValue === '' || ! $positionId) {
            $issues[] = $positionValue === ''
                ? 'Latest position is blank.'
                : 'Latest position has no approved position mapping.';
        } elseif (! Position::query()->whereKey($positionId)->where('is_active', true)
            ->where(function ($query) use ($departmentId) {
                $query->whereNull('department_id')->orWhere('department_id', $departmentId);
            })->exists()) {
            $issues[] = 'The mapped position is no longer active or does not match the department.';
        }

        $birthDateCell = $this->cellByHeader($data, 'BDATE');
        $birthDate = $reviewEmployee['date_of_birth'] ?? $this->dateValue($birthDateCell);
        $mappedAmbiguousCell = $mapping['date_target'] !== 'source_only'
            ? $this->cellByColumn($data, (int) $mapping['date_source_column'])
            : null;
        $mappedAmbiguousDate = $this->dateValue($mappedAmbiguousCell);
        $hasMappedAmbiguousDate = trim((string) ($mappedAmbiguousCell['display_value'] ?? '')) !== '';
        if ($hasMappedAmbiguousDate && ! $mappedAmbiguousDate) {
            $issues[] = 'The additional mapped date could not be interpreted; correct this record.';
        }
        if ($mapping['date_target'] === 'date_of_birth' && $hasMappedAmbiguousDate) {
            $birthDate = $mappedAmbiguousDate;
        }
        if (! $birthDate || $birthDate >= today()->toDateString()) {
            $issues[] = 'Birth date is missing, invalid, or not in the past.';
        }
        $dateHired = $format === 'plantilla'
            ? $this->dateValue($this->cellByHeader($data, 'DATE OF ORIGINAL APPOINTMENT'))
            : $this->dateValue($this->cellByHeader($data, 'DATE APPOINTED (1ST)'));
        $retirementCell = $this->cellByHeader($data, 'EXPECTED DATE OF MANDATORY RETIREMENT');
        $retirementDate = $this->dateValue($retirementCell);
        if ($retirementCell && trim((string) $retirementCell['display_value']) !== '' && ! $retirementDate) {
            $issues[] = 'Expected retirement date could not be interpreted; correct this record.';
        }
        if ($mapping['date_target'] === 'date_hired' && $hasMappedAmbiguousDate) {
            $dateHired = $mappedAmbiguousDate;
        }
        if ($format === 'plantilla') {
            $history = $dateHired ? [[
                'position_title' => $positionValue,
                'appointment_date' => $dateHired,
                'sequence' => 1,
            ]] : [];
        } else {
            $history = $this->employmentHistory($data, $issues);
        }
        $sourceEmployeeNumber = $format === 'plantilla'
            ? ''
            : trim((string) ($this->cellByHeader($data, 'NO.')['display_value'] ?? ''));
        $employeeNumber = trim((string) ($reviewEmployee['employee_no'] ?? $sourceEmployeeNumber));
        if ($format === 'legacy' && ($employeeNumber === '' || mb_strlen($employeeNumber) > 255)) {
            $issues[] = 'Employee reference number is required.';
        }
        if ($format === 'plantilla' && trim((string) ($identity['item_number'] ?? '')) === '') {
            $issues[] = 'A plantilla item number is required to generate a safe HRIS employee number.';
        }
        $existingByNumber = $employeeNumber !== ''
            ? Employee::withTrashed()->with('user')
                ->whereRaw('LOWER(employee_no) = ?', [mb_strtolower($employeeNumber)])
                ->first()
            : null;
        $existingByIdentity = ! $existingByNumber && ! empty($identity['hash'])
            ? EmployeeServiceProfile::query()
                ->with('employee.user')
                ->where('source_identity_hash', $identity['hash'])
                ->first()?->employee
            : null;
        $existing = $existingByNumber ?? $existingByIdentity;
        if ($existing && $employeeNumber === '') {
            $employeeNumber = $existing->employee_no;
        }
        if ($existing?->trashed()) {
            $issues[] = 'An archived employee already uses this employee number. Review the archived record before importing.';
        }
        if ($existing?->user?->role === 'admin') {
            $issues[] = 'This employee number belongs to a protected Admin account. Imports cannot change that employee or account.';
        }
        $possibleNameMatches = $existing || ! $name
            ? []
            : $this->possibleEmployeeMatches($name);
        $unconfirmedNameMatches = $possibleNameMatches && empty($reviewEmployee['confirm_distinct_employee']);
        if ($unconfirmedNameMatches) {
            $numbers = collect($possibleNameMatches)->pluck('employee_no')->filter()->implode(', ');
            $issues[] = 'A current employee has the same normalized name'.($numbers !== '' ? " (employee number(s): {$numbers})" : '').'. Confirm this is a different person or correct the employee number.';
        } else {
            $possibleNameMatches = [];
        }

        $positionLevel = $reviewProfile['position_level'] ?? $this->displayByHeader($data, 'POSITION LEVEL');
        $category = $reviewProfile['source_classification'] ?? $this->displayByHeader(
            $data,
            $format === 'plantilla' ? 'STATUS' : 'CAT'
        );
        $gender = trim((string) ($reviewEmployee['sex'] ?? $this->displayByHeader($data, 'GENDER')));
        $natureOfAppointment = $reviewEmployee['employment_type'] ?? $this->displayByHeader(
            $data,
            $format === 'plantilla' ? 'STATUS' : 'NATURE OF APPOINTMENT'
        );
        $linkedUser = $existing?->user;
        $sourceEmail = trim((string) ($reviewEmployee['email'] ?? $this->emailFromSource($data) ?? ''));
        $email = $sourceEmail;
        if ($email === '') {
            $existingEmail = trim((string) ($existing?->email ?? ''));
            $email = filter_var($existingEmail, FILTER_VALIDATE_EMAIL)
                ? $existingEmail
                : (string) ($linkedUser?->email ?? '');
        }
        $email = mb_strtolower(trim($email));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'The employee email address is invalid.';
        } elseif ($email !== '' && User::query()->whereRaw('LOWER(email) = ?', [$email])
            ->when($linkedUser, fn ($query) => $query->where('id', '!=', $linkedUser->id))
            ->exists()) {
            $issues[] = 'This email address is already used by another login account.';
        }
        if (trim((string) $positionLevel) === '' || mb_strlen((string) $positionLevel) > 255) {
            $issues[] = 'Position level is missing or exceeds the supported length.';
        }
        if (trim((string) $category) === '' || mb_strlen((string) $category) > 255) {
            $issues[] = 'Employment category is missing or exceeds the supported length.';
        }
        if (! in_array(mb_strtolower($gender), ['m', 'f', 'male', 'female', 'other'], true)) {
            $issues[] = 'Gender is missing or has an unsupported value.';
        }
        if (trim((string) $natureOfAppointment) === '' || mb_strlen((string) $natureOfAppointment) > 60) {
            $issues[] = 'Nature of appointment is missing or exceeds the supported length.';
        }
        $department = $departmentId ? Department::find($departmentId) : null;
        $role = $linkedUser?->role ?? 'employee';
        if ($role === 'office_head' && $department?->head_user_id
            && (int) $department->head_user_id !== (int) ($linkedUser?->id ?? 0)) {
            $issues[] = 'This department already has an assigned Office Head; confirm the correct assignment before importing another.';
        }
        $statusCell = $format === 'plantilla'
            ? null
            : ($this->cellByHeader($data, 'EMPLOYMENT STATUS') ?? $this->cellByHeader($data, 'STATUS'));
        $sourceEmploymentStatus = $statusCell
            ? $this->parseEmploymentStatus(trim((string) ($statusCell['display_value'] ?? '')))
            : null;
        if ($statusCell && trim((string) ($statusCell['display_value'] ?? '')) !== '' && ! $sourceEmploymentStatus) {
            $issues[] = 'Employment status is not a supported value.';
        } elseif ($statusCell && trim((string) ($statusCell['display_value'] ?? '')) === '') {
            $issues[] = 'Employment status is missing.';
        }

        $licenseCell = $this->cellByHeader($data, 'LICENSE- DATE EXPIRATION');
        $licenseExpirationDate = $licenseCell
            && is_numeric($licenseCell['value'])
            && ExcelDate::isDateTimeFormatCode((string) $licenseCell['number_format'])
            ? $this->dateValue($licenseCell)
            : null;
        $profile = [
            'position_level' => $positionLevel,
            'source_classification' => $category,
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
            'source_identity_hash' => $identity['hash'] ?? null,
            'source_identity_type' => $identity['type'] ?? null,
            'plantilla_item_no' => $identity['item_number'] ?? null,
        ];

        return [
            'issues' => array_values(array_unique($issues)),
            'existing_employee_id' => $existing?->id,
            'identity_hash' => $identity['hash'] ?? null,
            'identity_type' => $identity['type'] ?? null,
            'plantilla_item_no' => $identity['item_number'] ?? null,
            'possible_name_matches' => $possibleNameMatches,
            'account_action' => $linkedUser ? 'existing' : 'create',
            'department_name' => $department?->name,
            'position_title' => $positionValue,
            'employee' => [
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'employee_no' => $employeeNumber,
                'email' => $email,
                'first_name' => $name['first_name'] ?? '',
                'middle_name' => $name['middle_name'] ?? null,
                'last_name' => $name['last_name'] ?? '',
                'suffix' => $name['suffix'] ?? null,
                'sex' => $gender,
                'date_of_birth' => $birthDate,
                'employment_status' => $sourceEmploymentStatus ?? ($mapping['employment_status'] ?? 'Active'),
                'employment_type' => $natureOfAppointment,
                'date_hired' => $dateHired,
            ],
            'profile' => $profile,
            'history' => $history,
            'role' => $role,
            'employment_status_from_source' => $statusCell && trim((string) ($statusCell['display_value'] ?? '')) !== '',
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
                $issues[] = 'An appointment-history date could not be interpreted.';
            }
            if ($position !== '' || $date !== null) {
                if ($position === '') {
                    $issues[] = 'An appointment date has no associated position.';

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

    private function parseName(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',')) {
            [$lastName, $givenNames] = array_map('trim', explode(',', $value, 2));
            $suffix = $this->extractSuffix($lastName);
            if ($suffix !== null) {
                $lastName = $this->removeSuffix($lastName, $suffix);
            } else {
                $suffix = $this->extractSuffix($givenNames);
                if ($suffix !== null) {
                    $givenNames = $this->removeSuffix($givenNames, $suffix);
                }
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

        $parts = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) {
            return null;
        }
        $suffix = $this->extractSuffix(end($parts));
        if ($suffix !== null) {
            array_pop($parts);
        }
        $lastNameStart = count($parts) - 1;
        $surnameParticles = ['da', 'das', 'de', 'del', 'della', 'der', 'di', 'dos', 'du', 'la', 'le', 'van', 'von'];
        while ($lastNameStart > 1 && in_array(mb_strtolower($parts[$lastNameStart - 1]), $surnameParticles, true)) {
            $lastNameStart--;
        }
        $lastName = implode(' ', array_slice($parts, $lastNameStart));
        $givenParts = array_slice($parts, 0, $lastNameStart);

        return [
            'first_name' => array_shift($givenParts),
            'middle_name' => implode(' ', $givenParts) ?: null,
            'last_name' => $lastName,
            'suffix' => $suffix,
        ];
    }

    private function possibleEmployeeMatches(array $name): array
    {
        $firstName = trim((string) ($name['first_name'] ?? ''));
        $lastName = trim((string) ($name['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return [];
        }

        return Employee::withTrashed()
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($lastName)])
            ->get()
            ->filter(fn (Employee $employee) => $this->normalizeMatchValue((string) $employee->middle_name)
                    === $this->normalizeMatchValue((string) ($name['middle_name'] ?? ''))
                && $this->normalizeMatchValue((string) $employee->suffix)
                    === $this->normalizeMatchValue((string) ($name['suffix'] ?? '')))
            ->map(fn (Employee $employee) => [
                'employee_no' => $employee->employee_no,
                'full_name' => $employee->full_name,
                'archived' => $employee->trashed(),
            ])
            ->values()
            ->all();
    }

    private function existingMayorForSourceRow(array $sourceData, string $format): ?User
    {
        $name = $format === 'plantilla'
            ? [
                'first_name' => trim((string) ($this->displayByHeader($sourceData, 'FIRST NAME') ?? '')),
                'middle_name' => trim((string) ($this->displayByHeader($sourceData, 'MIDDLE NAME') ?? '')),
                'last_name' => trim((string) ($this->displayByHeader($sourceData, 'LAST NAME') ?? '')),
                'suffix' => '',
            ]
            : $this->parseName(trim((string) ($this->cellByHeader($sourceData, 'NAME')['display_value'] ?? '')));
        if (! $name) {
            return null;
        }

        $sourceName = $this->normalizeMatchValue(implode(' ', array_filter($name)));
        if ($sourceName === '') {
            return null;
        }

        $matches = User::query()
            ->where('role', 'mayor')
            ->with('employee')
            ->get()
            ->filter(function (User $mayor) use ($sourceName) {
                return collect([$mayor->name, $mayor->employee?->full_name])
                    ->filter()
                    ->contains(fn (string $accountName) => $this->normalizeMatchValue($accountName) === $sourceName);
            });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function extractSuffix(string $value): ?string
    {
        if (! preg_match('/(?:^|\s)(Jr\.?|Sr\.?|II|III|IV|V)$/i', trim($value), $matches)) {
            return null;
        }

        return in_array(mb_strtoupper(rtrim($matches[1], '.')), ['II', 'III', 'IV', 'V'], true)
            ? mb_strtoupper(rtrim($matches[1], '.'))
            : ucfirst(mb_strtolower(rtrim($matches[1], '.'))).'.';
    }

    private function removeSuffix(string $value, string $suffix): string
    {
        return trim((string) preg_replace('/\s+'.preg_quote($suffix, '/').'\.?$/i', '', trim($value)));
    }

    private function emailFromSource(array $data): ?string
    {
        foreach ($data as $cell) {
            $header = $this->normalizeMatchValue((string) ($cell['header'] ?? ''));
            if (in_array($header, ['EMAIL', 'EMAILADDRESS', 'EMPLOYEEEMAIL', 'WORKEMAIL', 'EMAILADD'], true)
                && trim((string) ($cell['display_value'] ?? '')) !== '') {
                return trim((string) $cell['display_value']);
            }
        }

        return null;
    }

    private function parseEmploymentStatus(string $value): ?string
    {
        $normalized = $this->normalizeMatchValue($value);

        return match ($normalized) {
            'ACTIVE', 'EMPLOYED', 'INSERVICE', 'REGULAR' => 'Active',
            'ONLEAVE', 'LEAVE' => 'On Leave',
            'SEPARATED', 'RESIGNED', 'TERMINATED', 'INACTIVE' => 'Separated',
            'RETIRED' => 'Retired',
            default => null,
        };
    }

    private function generateInternalEmployeeNumber(): string
    {
        do {
            $employeeNumber = 'HRIS-'.Str::upper(Str::random(12));
        } while (Employee::query()->where('employee_no', $employeeNumber)->exists());

        return $employeeNumber;
    }

    private function matchScore(string $normalized, string $candidate): float
    {
        $candidate = $this->normalizeMatchValue($candidate);
        if ($normalized === '' || $candidate === '') {
            return 0;
        }
        similar_text($normalized, $candidate, $score);

        return round($score, 1);
    }

    private function normalizeMatchValue(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^[:alnum:]]+/u', '', trim($value)));
    }

    private function createDepartmentFromSource(string $sourceOffice, array $newDepartment): Department
    {
        $name = trim((string) ($newDepartment['name'] ?? ''));
        $code = trim((string) ($newDepartment['code'] ?? ''));
        $key = hash('sha256', $sourceOffice);

        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages([
                "new_departments.{$key}.name" => 'Enter a department name of no more than 150 characters.',
            ]);
        }
        if (empty($newDepartment['authorize']) || ! in_array((string) $newDepartment['authorize'], ['1', 'true', 'on'], true)) {
            throw ValidationException::withMessages([
                "new_departments.{$key}.authorize" => 'Explicit authorization is required to add a department beyond the approved list.',
            ]);
        }
        if (mb_strlen($code) > 20) {
            throw ValidationException::withMessages([
                "new_departments.{$key}.code" => 'The department code cannot exceed 20 characters.',
            ]);
        }

        $normalizedSource = $this->normalizeMatchValue($sourceOffice);
        $normalizedName = $this->normalizeMatchValue($name);
        $normalizedCode = $this->normalizeMatchValue($code);
        if ($normalizedName === '') {
            throw ValidationException::withMessages([
                "new_departments.{$key}.name" => 'Enter a valid department name.',
            ]);
        }

        $allDepartments = Department::query()->lockForUpdate()->get();
        $sourceMatches = $allDepartments->filter(fn (Department $department) => (
            $this->normalizeMatchValue($department->name) === $normalizedSource
            || $this->normalizeMatchValue($department->code ?? '') === $normalizedSource
            || collect($department->aliases ?? [])->contains(
                fn (string $alias) => $this->normalizeMatchValue($alias) === $normalizedSource
            )
        ));
        if ($sourceMatches->count() > 1) {
            throw ValidationException::withMessages([
                "department_map.{$key}" => 'The source office already matches multiple departments. Choose the correct existing department instead.',
            ]);
        }

        $sameName = $allDepartments->filter(fn (Department $department) => (
            $this->normalizeMatchValue($department->name) === $normalizedName
            || $this->normalizeMatchValue($department->code ?? '') === $normalizedName
        ));
        if ($sourceMatches->isNotEmpty()) {
            $department = $sourceMatches->first();
        } elseif ($sameName->count() === 1) {
            $department = $sameName->first();
        } elseif ($sameName->count() > 1) {
            throw ValidationException::withMessages([
                "new_departments.{$key}.name" => 'This name matches multiple existing departments. Select an existing department instead.',
            ]);
        } else {
            $department = null;
        }

        if (! $department && $allDepartments->contains(
            fn (Department $candidate) => collect($candidate->aliases ?? [])
                ->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalizedName)
        )) {
            throw ValidationException::withMessages([
                "new_departments.{$key}.name" => 'This name is already an approved alias for an existing department. Select that department instead.',
            ]);
        }

        if ($department && ! $department->is_active) {
            throw ValidationException::withMessages([
                "department_map.{$key}" => 'The matching department is inactive. Reactivate it or select another department.',
            ]);
        }

        if ($normalizedCode !== '') {
            $codeOwner = $allDepartments->first(fn (Department $candidate) => (
                $this->normalizeMatchValue($candidate->code ?? '') === $normalizedCode
            ));
            if ($codeOwner && (! $department || $codeOwner->id !== $department->id)) {
                throw ValidationException::withMessages([
                    "new_departments.{$key}.code" => 'This department code is already assigned to another department.',
                ]);
            }
            if (! $codeOwner && $allDepartments->contains(fn (Department $candidate) => (
                (! $department || (int) $candidate->id !== (int) $department->id)
                && (
                    $this->normalizeMatchValue($candidate->name) === $normalizedCode
                    || collect($candidate->aliases ?? [])
                        ->contains(fn (string $alias) => $this->normalizeMatchValue($alias) === $normalizedCode)
                )
            ))) {
                throw ValidationException::withMessages([
                    "new_departments.{$key}.code" => 'This department code conflicts with another department name or approved alias.',
                ]);
            }
        }

        if (! $department) {
            $department = Department::create([
                'name' => $name,
                'code' => $code !== '' ? $code : null,
                'aliases' => [$sourceOffice],
                'is_active' => true,
            ]);
        } else {
            $aliases = $department->aliases ?? [];
            if (! collect($aliases)->contains(
                fn (string $alias) => $this->normalizeMatchValue($alias) === $normalizedSource
            ) && $this->normalizeMatchValue($department->name) !== $normalizedSource
                && $this->normalizeMatchValue($department->code ?? '') !== $normalizedSource) {
                $aliases[] = $sourceOffice;
                $department->update(['aliases' => array_values($aliases)]);
            }
        }

        return $department;
    }

    private function createPositionFromSource(
        string $sourceTitle,
        int $departmentId,
        array $newPosition,
        string $key
    ): Position {
        $title = trim((string) ($newPosition['title'] ?? $sourceTitle));
        $salaryGrade = trim((string) ($newPosition['salary_grade'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) {
            throw ValidationException::withMessages([
                "new_positions.{$key}.title" => 'Enter a position title of no more than 150 characters.',
            ]);
        }
        if (mb_strlen($salaryGrade) > 10) {
            throw ValidationException::withMessages([
                "new_positions.{$key}.salary_grade" => 'The salary grade cannot exceed 10 characters.',
            ]);
        }

        $department = Department::query()
            ->where('is_active', true)
            ->lockForUpdate()
            ->find($departmentId);
        if (! $department) {
            throw ValidationException::withMessages([
                "position_map.{$key}" => 'Select an existing active department before creating a position.',
            ]);
        }
        $normalizedSource = $this->normalizeMatchValue($sourceTitle);
        $normalizedTitle = $this->normalizeMatchValue($title);
        $matches = Position::query()->where(function ($query) use ($departmentId) {
            $query->where('department_id', $departmentId)->orWhereNull('department_id');
        })->lockForUpdate()->get()
            ->filter(fn (Position $position) => (
                $this->normalizeMatchValue($position->title) === $normalizedSource
                || $this->normalizeMatchValue($position->title) === $normalizedTitle
                || collect($position->aliases ?? [])->contains(
                    fn (string $alias) => in_array($this->normalizeMatchValue($alias), [$normalizedSource, $normalizedTitle], true)
                )
            ));
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['position_map' => "The source title \"{$sourceTitle}\" matches multiple positions. Choose the correct existing position."]);
        }
        if ($existing = $matches->first()) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages(['position_map' => "The existing position \"{$sourceTitle}\" is inactive. Activate it or choose another position."]);
            }
            if ($salaryGrade !== ''
                && trim((string) $existing->salary_grade) !== ''
                && $this->normalizeMatchValue($salaryGrade) !== $this->normalizeMatchValue((string) $existing->salary_grade)) {
                throw ValidationException::withMessages([
                    'position_map' => "The matching position \"{$existing->title}\" already exists with salary grade {$existing->salary_grade}. Select that position to confirm it rather than creating a duplicate.",
                ]);
            }

            return $existing;
        }

        $aliases = $normalizedSource !== $normalizedTitle ? [$sourceTitle] : [];

        return Position::create([
            'title' => $title,
            'department_id' => $departmentId,
            'salary_grade' => $salaryGrade !== '' ? $salaryGrade : null,
            'aliases' => $aliases,
            'is_active' => true,
        ]);
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
        $cell = $this->cellByHeader($data, $header, $occurrence);

        return $cell ? $cell['display_value'] : null;
    }

    private function cellByHeader(array $data, string $header, int $occurrence = 1): ?array
    {
        $wanted = $this->canonicalHeader($header);
        foreach ($data as $cell) {
            $actual = $cell['canonical_header'] ?? $this->canonicalHeader((string) ($cell['header'] ?? ''));
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
            $actual = $sourceHeader['canonical_label']
                ?? $this->canonicalHeader((string) ($sourceHeader['label'] ?? ''));
            if ($actual === $this->canonicalHeader($header)) {
                return (int) $sourceHeader['index'];
            }
        }

        throw ValidationException::withMessages(['file' => 'The workbook is missing the required latest-position column.']);
    }

    private function headerColumnFromData(array $data, string $header): int
    {
        foreach ($data as $cell) {
            $actual = $cell['canonical_header'] ?? $this->canonicalHeader((string) ($cell['header'] ?? ''));
            if ($actual === $this->canonicalHeader($header)) {
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
