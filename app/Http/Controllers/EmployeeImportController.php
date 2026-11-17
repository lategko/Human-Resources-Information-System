<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\EmployeeImport;
use App\Models\EmployeeImportRow;
use App\Models\Position;
use App\Services\AuditLogger;
use App\Services\EmployeeWorkbookImportService;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $rows = $employeeImport->rows()->orderBy('source_row')->paginate(10);
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
        $rows->getCollection()->load('reviewer');

        return view('employee-imports.show', [
            'import' => $employeeImport->load(['uploader', 'mappingApprover', 'previewer', 'confirmer', 'mappingEvents.changer']),
            'rows' => $rows,
            'parsedNames' => $parsedNames,
            'correctionDefaults' => $correctionDefaults,
            'officeColumns' => $officeColumns,
            'suggestedOfficeColumn' => $suggestedOfficeColumn,
            'ambiguousColumns' => $importer->ambiguousColumns($employeeImport),
            'selectedOfficeColumn' => $selectedOfficeColumn,
            'officeValues' => $officeValues,
            'positionPairs' => $positionPairs,
            'mappingSummary' => $mappingSummary,
            'summary' => $summary,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(),
            'canEditMappings' => in_array($employeeImport->status, [
                'mapping_required', 'ready_for_import', 'completed_with_errors', 'failed',
                'pending_review', 'mapping_approved', 'review_required',
            ], true),
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
            'position_map' => ['nullable', 'array'],
            'position_map.*' => ['nullable'],
        ]);

        $importer->saveMapping($employeeImport, $data, $request->user()->id);
        $employeeImport->refresh();
        $auditLogger->record('employee_import.mapping_approved', $employeeImport, [
            'office_source_column' => $employeeImport->field_mappings['office_source_column'],
            'department_mapping_count' => count($employeeImport->field_mappings['department_map']),
            'position_mapping_count' => count($employeeImport->field_mappings['position_map']),
        ]);

        return back()->with('success', 'Mappings saved and employee records validated. Valid records are ready to import; unresolved records can be corrected separately.');
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
        $completed = $importer->confirm($employeeImport, $request->user()->id);
        $setupLinksSent = 0;
        $setupLinksFailed = 0;
        $activationPending = 0;
        $accountRows = $completed->rows()->whereIn('status', ['imported', 'updated'])->get()
            ->filter(fn (EmployeeImportRow $row) => ($row->review_data['import_result']['account_created'] ?? false)
                && empty($row->review_data['import_result']['setup_link_sent_at']));
        $accountRows->load('employee.user');
        foreach ($accountRows as $row) {
            if (! $row->employee?->user) {
                continue;
            }
            if (! $row->employee->user->email) {
                $activationPending++;

                continue;
            }
            try {
                $status = Password::sendResetLink(['email' => $row->employee->user->email]);
            } catch (TransportExceptionInterface $exception) {
                report($exception);
                $setupLinksFailed++;

                continue;
            }
            if ($status === PasswordBroker::RESET_LINK_SENT) {
                $row->update([
                    'review_data' => array_replace_recursive($row->review_data, [
                        'import_result' => ['setup_link_sent_at' => now()->toIso8601String()],
                    ]),
                ]);
                $setupLinksSent++;
            } else {
                $setupLinksFailed++;
            }
        }
        $auditLogger->record('employee_import.confirmed', $completed, [
            'imported' => $completed->imported_count,
            'updated' => $completed->updated_count,
            'accounts_created' => $completed->accounts_created_count,
            'skipped' => $completed->skipped_count,
            'needs_review' => $completed->review_count,
            'account_setup_links_sent' => $setupLinksSent,
            'account_setup_links_failed' => $setupLinksFailed,
            'account_activation_pending' => $activationPending,
        ]);

        $message = "Import finished: {$completed->imported_count} new employee(s), {$completed->updated_count} updated, {$completed->accounts_created_count} account(s) created, {$completed->skipped_count} duplicate(s) skipped, {$completed->review_count} record(s) requiring correction. {$setupLinksSent} setup link(s) sent; {$activationPending} account(s) need an email address before HR can send an activation link.";
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
