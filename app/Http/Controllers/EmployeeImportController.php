<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\EmployeeImport;
use App\Models\EmployeeImportRow;
use App\Models\Position;
use App\Services\AuditLogger;
use App\Services\EmployeeWorkbookImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeImportController extends Controller
{
    public function index(): View
    {
        return view('employee-imports.index', [
            'imports' => EmployeeImport::with(['uploader', 'mappingApprover', 'confirmer'])
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
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx'],
        ]);
        $import = $importer->stage($request->file('file'), $request->user()->id);
        $auditLogger->record('employee_import.staged', $import, [
            'rows' => $import->row_count,
            'source_sha256' => $import->source_sha256,
        ]);

        return redirect()->route('employee-imports.show', $import)
            ->with('success', "Workbook #{$import->id} staged for HRMO review. No employee records were created.");
    }

    public function show(Request $request, EmployeeImport $employeeImport, EmployeeWorkbookImportService $importer): View
    {
        $rows = $employeeImport->rows()->orderBy('source_row')->paginate(10);
        $officeColumns = $importer->availableOfficeColumns($employeeImport);
        $requestedOfficeColumn = $request->query('office_column');
        $selectedOfficeColumn = (int) ($requestedOfficeColumn
            ?? $employeeImport->field_mappings['office_source_column']
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
        $nameFormat = $employeeImport->field_mappings['name_format'] ?? null;
        $parsedNames = $rows->getCollection()->mapWithKeys(fn ($row) => [
            $row->id => $row->review_data['name']
                ?? ($nameFormat ? $importer->parsedName($row, $nameFormat) : null),
        ]);
        $rows->getCollection()->load('reviewer');

        return view('employee-imports.show', [
            'import' => $employeeImport->load(['uploader', 'mappingApprover', 'previewer', 'confirmer', 'mappingEvents.changer']),
            'rows' => $rows,
            'parsedNames' => $parsedNames,
            'officeColumns' => $officeColumns,
            'ambiguousColumns' => $importer->ambiguousColumns($employeeImport),
            'selectedOfficeColumn' => $selectedOfficeColumn,
            'officeValues' => $officeValues,
            'positionPairs' => $positionPairs,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('is_active', true)->orderBy('title')->get(),
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
            'name_format' => ['required', Rule::in(['surname_comma_given', 'given_middle_surname'])],
            'employment_status' => ['required', Rule::in(['Active', 'On Leave', 'Separated', 'Retired'])],
            'date_source_column' => ['nullable', 'integer'],
            'date_target' => ['required', Rule::in(['source_only', 'date_of_birth', 'date_hired', 'license_expiration_date', 'expected_retirement_date'])],
            'ambiguous_field_notes' => ['nullable', 'array'],
            'ambiguous_field_notes.*' => ['nullable', 'string', 'max:500'],
            'department_map' => ['required', 'array'],
            'department_map.*' => ['required', 'integer', 'exists:departments,id'],
            'position_map' => ['required', 'array'],
            'position_map.*' => ['required', 'integer', 'exists:positions,id'],
        ]);

        $importer->saveMapping($employeeImport, $data, $request->user()->id);
        $employeeImport->refresh();
        $auditLogger->record('employee_import.mapping_approved', $employeeImport, [
            'office_source_column' => $employeeImport->field_mappings['office_source_column'],
            'department_mapping_count' => count($employeeImport->field_mappings['department_map']),
            'position_mapping_count' => count($employeeImport->field_mappings['position_map']),
        ]);

        return back()->with('success', 'HRMO field mappings saved and audited. Review the preview before confirming import.');
    }

    public function reviewRow(
        Request $request,
        EmployeeImport $employeeImport,
        EmployeeImportRow $employeeImportRow,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $name = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
        ]);
        $importer->saveRowReview($employeeImport, $employeeImportRow, $name, $request->user()->id);
        $auditLogger->record('employee_import.row_reviewed', $employeeImportRow, [
            'source_row' => $employeeImportRow->source_row,
            'fields' => array_keys($name),
        ]);

        return back()->with('success', "Source row {$employeeImportRow->source_row} name review saved.");
    }

    public function confirm(
        Request $request,
        EmployeeImport $employeeImport,
        EmployeeWorkbookImportService $importer,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $request->validate(['confirm_import' => ['required', 'accepted']]);
        $completed = $importer->confirm($employeeImport, $request->user()->id);
        $auditLogger->record('employee_import.confirmed', $completed, [
            'imported' => $completed->imported_count,
            'skipped' => $completed->skipped_count,
            'needs_review' => $completed->review_count,
        ]);

        return redirect()->route('employee-imports.show', $completed)
            ->with('success', "Import finished: {$completed->imported_count} imported, {$completed->skipped_count} skipped, {$completed->review_count} requiring review.");
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
