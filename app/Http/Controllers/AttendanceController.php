<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\BiometricImport;
use App\Models\Department;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\BiometricCsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $query = AttendanceRecord::with('employee.department');
        if ($user->isEmployee()) {
            $query->where('employee_id', $user->employee?->id ?? 0);
        } elseif ($user->isOfficeHead()) {
            $query->whereHas('employee.department', fn ($department) => $department->where('head_user_id', $user->id));
        }
        if ($request->filled('date')) {
            $query->whereDate('work_date', $request->date('date'));
        } else {
            $query->whereBetween('work_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        }
        if ($request->filled('department_id') && ! $user->isOfficeHead() && ! $user->isEmployee()) {
            $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $request->integer('department_id')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('employee', fn ($employee) => $employee->where('employee_no', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        }

        return view('attendance.index', [
            'records' => $query->latest('work_date')->paginate(25)->withQueryString(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'imports' => $user->canManageHr() || $user->isAdministrator()
                ? BiometricImport::withCount('records')->latest()->limit(5)->get()
                : collect(),
        ]);
    }

    public function import(Request $request, BiometricCsvImportService $importer, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);
        $import = $importer->import($request->file('file'), $request->user()->id);
        $auditLogger->record('attendance.biometric_imported', $import, [
            'imported' => $import->imported_count,
            'errors' => $import->error_count,
        ]);

        return redirect()->route('attendance.index')->with('success', "Import #{$import->id}: {$import->imported_count} row(s) imported, {$import->error_count} error(s).");
    }

    public function importDetails(BiometricImport $biometricImport): View
    {
        abort_unless(auth()->user()->canManageHr() || auth()->user()->isAdministrator(), 403);

        return view('attendance.import', [
            'import' => $biometricImport->load('records.employee'),
        ]);
    }

    public function template(): Response
    {
        return response()->streamDownload(function () {
            $stream = fopen('php://output', 'wb');
            fputcsv($stream, ['biometric_id', 'date', 'time_in', 'time_out']);
            fputcsv($stream, ['BIO-0001', '2026-01-15', '08:00', '17:00']);
            fclose($stream);
        }, 'biometric-attendance-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function correct(Request $request, AttendanceRecord $attendance, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($request->user()->canManageHr() || $request->user()->isAdministrator(), 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['Present', 'Late', 'Absent', 'On Leave', 'Half Day', 'Rest Day', 'Holiday'])],
            'time_in' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'late_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'undertime_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'correction_remarks' => ['required', 'string', 'max:2000'],
        ]);
        DB::transaction(function () use ($attendance, $data, $request) {
            $locked = AttendanceRecord::query()->lockForUpdate()->findOrFail($attendance->id);
            $locked->update($data + ['corrected_by' => $request->user()->id]);
        });
        $auditLogger->record('attendance.corrected', $attendance, [
            'remarks' => $data['correction_remarks'],
            'status' => $data['status'],
            'changes' => array_intersect_key($data, array_flip(['status', 'time_in', 'time_out', 'late_minutes', 'undertime_minutes'])),
        ]);

        return back()->with('success', 'Attendance correction saved with an audit record.');
    }
}
