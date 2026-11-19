<?php

namespace App\Http\Controllers;

use App\Models\EmployeeImport;
use App\Models\EmployeeImportRow;
use App\Models\Position;
use App\Services\AuditLogger;
use App\Services\EmployeeWorkbookImportService;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmployeeImportController extends Controller
{
    public function index(): View
    {
        return view('employee-imports.index', [
            'imports' => EmployeeImport::with(['uploader', 'mappingApprover', 'confirmer'])
                ->withCount([
                    'rows as imported_rows_count' => fn ($query) => $query->where('status', 'imported'),
                    'rows as updated_rows_count' => fn ($query) => $query->where('status', 'updated'),
                    'rows as ready_rows_count' => fn ($query) => $query->where('status', 'ready'),
                    'rows as ready_updates_count' => fn ($query) => $query->where('status', 'ready_to_update'),
                    'rows as duplicate_rows_count' => fn ($query) => $query->where('status', 'duplicate'),
                    'rows as correction_rows_count' => fn ($query) => $query->where('status', 'needs_review'),
                    'rows as failed_rows_count' => fn ($query) => $query->where('status', 'failed'),
                ])
                ->latest()
                ->paginate(15),
        ]);
    }

    public function store(
        Request $request,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls'],
        ]);
        $import = $importer->stage($request->file('file'), $request->user()->id);
        $auditLogger->record('employee_import.staged', $import, [
            'rows' => $import->row_count,
            'source_sha256' => $import->source_sha256,
        ]);

        return redirect()->route('employee-imports.show', $import)
            ->with('success', "Workbook #{$import->id} uploaded. Detected {$import->row_count} employee record(s) on the {$import->worksheet_name} worksheet.");
    }

    public function show(Request $request, EmployeeImport $employeeImport, EmployeeWorkbookImportService $importer): View
    {
        $filters = $request->validate([
            'row_status' => ['nullable', Rule::in([
                'all', 'needs_attention', 'needs_review', 'awaiting_mapping', 'duplicate', 'failed',
                'awaiting_validation', 'ready', 'ready_to_update', 'imported', 'updated',
                'possible_duplicates',
            ])],
            'row_search' => ['nullable', 'string', 'max:100'],
        ]);
        $rowStatus = $filters['row_status'] ?? 'needs_attention';
        $rowSearch = trim($filters['row_search'] ?? '');
        $rowQuery = $employeeImport->rows()->orderBy('source_row');
        $inMemoryFilter = $rowSearch !== '' || in_array($rowStatus, [
            'possible_duplicates', 'awaiting_mapping', 'needs_review',
        ], true);

        if ($rowStatus === 'needs_attention') {
            $rowQuery->whereIn('status', ['needs_review', 'duplicate', 'failed']);
        } elseif ($rowStatus === 'awaiting_validation') {
            $rowQuery->whereIn('status', ['unvalidated', 'existing_to_update']);
        } elseif (in_array($rowStatus, ['needs_review', 'awaiting_mapping'], true)) {
            $rowQuery->where('status', 'needs_review');
        } elseif (in_array($rowStatus, ['duplicate', 'failed', 'ready', 'ready_to_update', 'imported', 'updated'], true)) {
            $rowQuery->where('status', $rowStatus);
        }

        if ($inMemoryFilter) {
            $filteredRows = $rowQuery->get();
            if ($rowStatus === 'possible_duplicates') {
                $filteredRows = $filteredRows->filter(
                    fn (EmployeeImportRow $row) => ! empty($row->review_data['classification']['possible_name_matches'])
                );
            } elseif ($rowStatus === 'awaiting_mapping') {
                $filteredRows = $filteredRows->filter(
                    fn (EmployeeImportRow $row) => $importer->hasUnresolvedMappingIssues($row)
                );
            } elseif ($rowStatus === 'needs_review') {
                $filteredRows = $filteredRows->filter(
                    fn (EmployeeImportRow $row) => $importer->needsEmployeeCorrection($row)
                );
            }
            if ($rowSearch !== '') {
                $needle = mb_strtolower($rowSearch);
                $filteredRows = $filteredRows->filter(function (EmployeeImportRow $row) use ($importer, $needle) {
                    $name = collect($importer->parsedName($row) ?? [])->filter()->implode(' ');
                    $source = collect($row->source_data)->pluck('display_value')->filter()->implode(' ');
                    $searchable = mb_strtolower($row->source_row.' '.$name.' '.$source);

                    return mb_strpos($searchable, $needle) !== false;
                });
            }
            $page = max(1, LengthAwarePaginator::resolveCurrentPage());
            $rows = new LengthAwarePaginator(
                $filteredRows->forPage($page, 10)->values(),
                $filteredRows->count(),
                10,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $rows = $rowQuery->paginate(10)->appends($request->query());
        }
        $officeColumns = collect($importer->availableOfficeColumns($employeeImport))
            ->map(function (array $header) use ($importer, $employeeImport) {
                $header['filled'] = $importer->officeValues($employeeImport, (int) $header['index'])->count();

                return $header;
            })->all();
        $suggestedOfficeColumn = $importer->suggestedOfficeColumn($employeeImport);
        $requestedOfficeColumn = $request->query('office_column');
        $selectedOfficeColumn = (int) ($requestedOfficeColumn
            ?? $employeeImport->field_mappings['office_source_column']
            ?? $suggestedOfficeColumn
            ?? 0);
        $selectedOfficeColumn = $selectedOfficeColumn === 0
            || in_array($selectedOfficeColumn, array_column($officeColumns, 'index'), true)
            ? $selectedOfficeColumn
            : 0;
        $officeValues = $selectedOfficeColumn
            ? $importer->officeValues($employeeImport, $selectedOfficeColumn)
            : collect();
        $positionPairs = $selectedOfficeColumn
            ? $importer->positionPairs($employeeImport, $selectedOfficeColumn)
            : collect();
        $mappingSummary = $importer->mappingSummary($employeeImport, $selectedOfficeColumn ?: null);
        $summary = $importer->importSummary($employeeImport, $selectedOfficeColumn ?: null);
        $parsedNames = $rows->getCollection()->mapWithKeys(fn ($row) => [
            $row->id => $row->review_data['name'] ?? $importer->parsedName($row),
        ]);
        $correctionDefaults = $rows->getCollection()->mapWithKeys(fn ($row) => [
            $row->id => $importer->correctionDefaults($row),
        ]);
        $rowReviewStates = $rows->getCollection()->mapWithKeys(fn (EmployeeImportRow $row) => [
            $row->id => [
                'awaiting_mapping' => $importer->hasUnresolvedMappingIssues($row),
                'needs_correction' => $importer->needsEmployeeCorrection($row),
            ],
        ]);
        $rows->getCollection()->load('reviewer');

        return view('employee-imports.show', [
            'import' => $employeeImport->load(['uploader', 'mappingApprover', 'previewer', 'confirmer', 'mappingEvents.changer']),
            'rows' => $rows,
            'parsedNames' => $parsedNames,
            'correctionDefaults' => $correctionDefaults,
            'rowReviewStates' => $rowReviewStates,
            'officeColumns' => $officeColumns,
            'suggestedOfficeColumn' => $suggestedOfficeColumn,
            'ambiguousColumns' => $importer->ambiguousColumns($employeeImport),
            'selectedOfficeColumn' => $selectedOfficeColumn,
            'officeValues' => $officeValues,
            'positionPairs' => $positionPairs,
            'mappingSummary' => $mappingSummary,
            'summary' => $summary,
            'departments' => $importer->officialDepartments(),
            'positions' => Position::with('department')->where('is_active', true)->orderBy('title')->get(),
            'canEditMappings' => in_array($employeeImport->status, [
                'mapping_required', 'ready_for_import', 'partially_ready', 'completed_with_errors', 'failed',
                'pending_review', 'mapping_approved', 'review_required',
            ], true),
            'rowStatus' => $rowStatus,
            'rowSearch' => $rowSearch,
        ]);
    }

    public function updateMapping(
        Request $request,
        EmployeeImport $employeeImport,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $data = $request->validate([
            'office_source_column' => ['required', 'integer'],
            'employment_status' => ['nullable', Rule::in(['Active', 'On Leave', 'Separated', 'Retired'])],
            'department_map' => ['nullable', 'array'],
            'department_map.*' => ['nullable'],
            'new_departments' => ['nullable', 'array'],
            'new_departments.*.name' => ['nullable', 'string', 'max:150'],
            'new_departments.*.code' => ['nullable', 'string', 'max:20'],
            'new_departments.*.authorize' => ['nullable', 'boolean'],
            'position_map' => ['nullable', 'array'],
            'position_map.*' => ['nullable'],
            'create_position' => ['nullable', 'array'],
            'create_position.*' => ['nullable', 'boolean'],
            'new_positions' => ['nullable', 'array'],
            'new_positions.*.title' => ['nullable', 'string', 'max:150'],
            'new_positions.*.salary_grade' => ['nullable', 'string', 'max:10'],
        ]);

        $mappingResult = $importer->saveMapping($employeeImport, $data, $request->user()->id);
        $employeeImport->refresh();
        $auditLogger->record('employee_import.mapping_approved', $employeeImport, [
            'office_source_column' => $employeeImport->field_mappings['office_source_column'],
            'department_mapping_count' => count($employeeImport->field_mappings['department_map']),
            'position_mapping_count' => count($employeeImport->field_mappings['position_map']),
            'positions_created' => count($mappingResult['created_positions']),
            'existing_positions_reused' => count($mappingResult['reused_positions']),
        ]);

        $message = 'Mappings saved and employee records validated. Valid records are ready to import; unresolved records can be corrected separately.';
        if ($mappingResult['created_positions']) {
            $message = count($mappingResult['created_positions']).' new position(s) created and mapped. Affected employee records were revalidated. '.$message;
        } elseif ($mappingResult['reused_positions']) {
            $message = 'An equivalent existing position was selected instead of creating a duplicate. Affected employee records were revalidated. '.$message;
        }

        return back()->with('success', $message);
    }

    public function reviewRow(
        Request $request,
        EmployeeImport $employeeImport,
        EmployeeImportRow $employeeImportRow,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $correction = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'employee_no' => ['nullable', 'string', 'max:255'],
            'sex' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'position_level' => ['nullable', 'string', 'max:255'],
            'source_classification' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'confirm_distinct_employee' => ['nullable', 'boolean'],
        ]);
        $importer->saveRowReview($employeeImport, $employeeImportRow, [
            'name' => array_intersect_key($correction, array_flip(['first_name', 'middle_name', 'last_name', 'suffix'])),
            'employee' => array_intersect_key($correction, array_flip([
                'employee_no', 'sex', 'date_of_birth', 'employment_type', 'email', 'confirm_distinct_employee',
            ])),
            'profile' => array_intersect_key($correction, array_flip(['position_level', 'source_classification'])),
        ], $request->user()->id);
        $auditLogger->record('employee_import.row_reviewed', $employeeImportRow, [
            'source_row' => $employeeImportRow->source_row,
            'fields' => array_keys($correction),
        ]);

        return back()->with('success', "Source row {$employeeImportRow->source_row} corrections saved and validation refreshed.");
    }

    public function confirm(
        Request $request,
        EmployeeImport $employeeImport,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $request->validate(['confirm_import' => ['required', 'accepted']]);
        $terminal = in_array($employeeImport->status, ['completed', 'completed_with_errors'], true);
        $hasPendingSetupLinks = $terminal && $employeeImport->rows()
            ->whereIn('status', ['imported', 'updated'])
            ->get(['review_data'])
            ->contains(fn (EmployeeImportRow $row) => ($row->review_data['import_result']['account_created'] ?? false)
                && empty($row->review_data['import_result']['setup_link_sent_at'])
                && empty($row->review_data['import_result']['setup_link_status']));
        $completed = $hasPendingSetupLinks
            ? $employeeImport
            : $importer->confirm($employeeImport, $request->user()->id);

        $accountRows = $completed->rows()->whereIn('status', ['imported', 'updated'])->orderBy('id')
            ->get(['id', 'employee_id', 'review_data'])
            ->filter(fn (EmployeeImportRow $row) => ($row->review_data['import_result']['account_created'] ?? false)
                && empty($row->review_data['import_result']['setup_link_sent_at'])
                && empty($row->review_data['import_result']['setup_link_status']))
            ->take(50);
        $accountRows->load('employee.user');
        foreach ($accountRows as $row) {
            if (! $row->employee?->user) {
                report(new \RuntimeException("Imported account for employee import row {$row->id} could not be loaded."));
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => ['setup_link_status' => 'failed'],
                    ]),
                ]);

                continue;
            }
            if (! $row->employee->user->email) {
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => ['setup_link_status' => 'email_missing'],
                    ]),
                ]);

                continue;
            }
            try {
                $status = Password::sendResetLink(['email' => $row->employee->user->email]);
            } catch (TransportExceptionInterface $exception) {
                report($exception);
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => ['setup_link_status' => 'failed'],
                    ]),
                ]);

                continue;
            }
            if ($status === PasswordBroker::RESET_LINK_SENT) {
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => [
                            'setup_link_sent_at' => now()->toIso8601String(),
                            'setup_link_status' => 'sent',
                        ],
                    ]),
                ]);
            } else {
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => ['setup_link_status' => 'failed'],
                    ]),
                ]);
            }
        }

        $setupLinkStatuses = $completed->rows()->whereIn('status', ['imported', 'updated'])->get(['review_data'])
            ->filter(fn (EmployeeImportRow $row) => $row->review_data['import_result']['account_created'] ?? false)
            ->map(fn (EmployeeImportRow $row) => $row->review_data['import_result']['setup_link_status']
                ?? (! empty($row->review_data['import_result']['setup_link_sent_at']) ? 'sent' : null));
        $setupLinksSent = $setupLinkStatuses->filter(fn (?string $status) => $status === 'sent')->count();
        $setupLinksFailed = $setupLinkStatuses->filter(fn (?string $status) => $status === 'failed')->count();
        $activationPending = $setupLinkStatuses->filter(fn (?string $status) => $status === 'email_missing')->count();
        $setupLinksPending = $setupLinkStatuses->filter(fn ($status) => $status === null)->count();

        if ($completed->status === 'importing' || $setupLinksPending > 0) {
            return redirect()->route('employee-imports.show', $completed)
                ->with('success', "Import is continuing in safe batches. {$completed->imported_count} employee(s) have been imported so far.");
        }

        $failedCount = $completed->rows()->where('status', 'failed')->count();
        $auditLogger->record('employee_import.confirmed', $completed, [
            'imported' => $completed->imported_count,
            'updated' => $completed->updated_count,
            'accounts_created' => $completed->accounts_created_count,
            'skipped' => $completed->skipped_count,
            'needs_review' => $completed->review_count - $failedCount,
            'failed' => $failedCount,
            'account_setup_links_sent' => $setupLinksSent,
            'account_setup_links_failed' => $setupLinksFailed,
            'account_activation_pending' => $activationPending,
        ]);

        $message = "Import finished: {$completed->imported_count} new employee(s), {$completed->updated_count} updated, {$completed->accounts_created_count} account(s) created, {$completed->skipped_count} duplicate(s) skipped, ".($completed->review_count - $failedCount)." record(s) requiring correction, {$failedCount} failed. {$setupLinksSent} setup link(s) sent; {$activationPending} account(s) need an email address before HR can send an activation link.";
        if ($setupLinksFailed > 0) {
            return redirect()->route('employee-imports.show', $completed)
                ->with('warning', "{$message} {$setupLinksFailed} account(s) did not receive a setup link; retry password setup after checking the mail service.");
        }

        return redirect()->route('employee-imports.show', $completed)->with('success', $message);
    }

    public function download(EmployeeImport $employeeImport, AuditLogger $auditLogger)
    {
        abort_unless(Storage::disk('local')->exists($employeeImport->source_file_path), 404);
        $auditLogger->record('employee_import.source_downloaded', $employeeImport);

        return Storage::disk('local')->download(
            $employeeImport->source_file_path,
            basename($employeeImport->original_name),
            ['Cache-Control' => 'private, no-store']
        );
    }
}
