<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLeaveApplicationRequest;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDocument;
use App\Models\LeaveApprovalHistory;
use App\Models\LeaveType;
use App\Notifications\LeaveApplicationUpdated;
use App\Services\AuditLogger;
use App\Services\WorkingDayCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class LeaveApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $query = LeaveApplication::with(['employee.department', 'leaveType']);
        $user = $request->user();
        if ($user->isEmployee()) {
            $query->where('employee_id', $user->employee?->id ?? 0);
        } elseif ($user->isOfficeHead()) {
            $query->whereHas('employee.department', fn ($department) => $department->where('head_user_id', $user->id));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $search = $request->string('q')->toString();
            $query->whereHas('employee', fn ($employee) => $employee
                ->where('employee_no', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%"));
        }

        return view('leave.index', ['applications' => $query->latest()->paginate(15)->withQueryString()]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LeaveApplication::class);
        $employee = $request->user()->employee->load(['department', 'position']);

        return view('leave.form', [
            'application' => new LeaveApplication([
                'filed_at' => today(),
                'office_snapshot' => $employee->department->name,
                'position_snapshot' => $employee->position?->title,
                'salary_snapshot' => $employee->monthly_salary,
                'last_name_snapshot' => $employee->last_name,
                'first_name_snapshot' => $employee->first_name,
                'middle_name_snapshot' => $employee->middle_name,
            ]),
            'employee' => $employee,
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(
        SaveLeaveApplicationRequest $request,
        WorkingDayCalculator $calculator,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $employee = $request->user()->employee;
        $application = DB::transaction(function () use ($request, $employee, $calculator, $auditLogger) {
            $application = new LeaveApplication();
            $this->saveForm($application, $request, $employee, $calculator);
            $application->save();
            $intent = $request->input('intent');
            $application->status = $intent === 'submit' ? 'Submitted' : 'Draft';
            if ($intent === 'submit') {
                $this->assertNoOverlap($application);
            }
            $application->save();

            LeaveApprovalHistory::create([
                'leave_application_id' => $application->id,
                'user_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => $application->status === 'Submitted' ? 'submitted' : 'draft_saved',
                'to_status' => $application->status,
            ]);
            if ($intent === 'submit') {
                LeaveApprovalHistory::create([
                    'leave_application_id' => $application->id,
                    'user_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => 'queued_for_office_head_review',
                    'from_status' => 'Submitted',
                    'to_status' => 'Under Office Head Review',
                ]);
                $application->status = 'Under Office Head Review';
                $application->save();
            }
            $auditLogger->record($application->status === 'Submitted' ? 'leave.submitted' : 'leave.draft_saved', $application);

            return $application;
        });
        $this->saveDocuments($request, $application);
        if ($application->status === 'Submitted') {
            $application->load('employee.department.head');
            $application->employee->department->head?->notify(new LeaveApplicationUpdated(
                $application,
                'A new leave application from '.$application->employee->full_name.' is awaiting review.'
            ));
        }

        return redirect()->route('leave.show', $application)->with('success', $application->status === 'Submitted'
            ? 'Your leave application has been submitted for review.'
            : 'Your draft has been saved.');
    }

    public function show(LeaveApplication $application): View
    {
        $this->authorize('view', $application);
        $application->load([
            'employee.department', 'employee.position', 'leaveType', 'documents', 'history.user',
        ]);
        $sensitiveDetailsVisible = ! $application->leaveType->is_sensitive
            || auth()->user()->canManageHr()
            || auth()->user()->isAdministrator()
            || auth()->user()->employee?->id === $application->employee_id;

        return view('leave.show', compact('application', 'sensitiveDetailsVisible'));
    }

    public function edit(LeaveApplication $application): View
    {
        $this->authorize('update', $application);
        $employee = $application->employee->load(['department', 'position']);

        return view('leave.form', [
            'application' => $application->load('leaveType'),
            'employee' => $employee,
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function update(
        SaveLeaveApplicationRequest $request,
        LeaveApplication $application,
        WorkingDayCalculator $calculator,
        AuditLogger $auditLogger
    ): RedirectResponse {
        $this->authorize('update', $application);
        $employee = $request->user()->employee;

        DB::transaction(function () use ($request, $application, $employee, $calculator, $auditLogger) {
            $previous = $application->status;
            $this->saveForm($application, $request, $employee, $calculator);
            if ($request->input('intent') === 'submit') {
                $this->assertNoOverlap($application);
                $application->status = 'Under Office Head Review';
                LeaveApprovalHistory::create([
                    'leave_application_id' => $application->id,
                    'user_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => 'resubmitted',
                    'from_status' => $previous,
                    'to_status' => 'Under Office Head Review',
                ]);
            }
            $application->save();
            $auditLogger->record('leave.updated', $application, ['status' => $application->status]);
        });
        $this->saveDocuments($request, $application);
        if ($application->status === 'Under Office Head Review') {
            $application->load('employee.department.head');
            $application->employee->department->head?->notify(new LeaveApplicationUpdated(
                $application,
                'A leave application from '.$application->employee->full_name.' is awaiting review.'
            ));
        }

        return redirect()->route('leave.show', $application)->with('success', 'Your application has been saved.');
    }

    public function cancel(Request $request, LeaveApplication $application, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('cancel', $application);
        DB::transaction(function () use ($request, $application, $auditLogger) {
            $locked = LeaveApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless(in_array($locked->status, ['Draft', 'Submitted', 'Under Office Head Review', 'Returned for Correction'], true), 409);
            $from = $locked->status;
            $locked->status = 'Cancelled';
            $locked->save();
            LeaveApprovalHistory::create([
                'leave_application_id' => $locked->id,
                'user_id' => $request->user()->id,
                'role' => $request->user()->role,
                'action' => 'cancelled',
                'from_status' => $from,
                'to_status' => 'Cancelled',
            ]);
            $auditLogger->record('leave.cancelled', $locked);
        });

        return redirect()->route('leave.index')->with('success', 'Leave application cancelled.');
    }

    public function print(LeaveApplication $application): View
    {
        $this->authorize('view', $application);
        $application->load(['employee.department', 'leaveType', 'documents', 'history.user']);
        $sensitiveDetailsVisible = ! $application->leaveType->is_sensitive
            || auth()->user()->canManageHr()
            || auth()->user()->isAdministrator()
            || auth()->user()->employee?->id === $application->employee_id;

        return view('leave.print', compact('application', 'sensitiveDetailsVisible'));
    }

    public function document(Request $request, LeaveApplication $application, LeaveApplicationDocument $document)
    {
        abort_unless($document->leave_application_id === $application->id, 404);
        $this->authorize('viewDocument', [$application, $document->is_sensitive || $application->leaveType->is_sensitive]);
        abort_unless(Storage::disk('local')->exists($document->stored_path), 404, 'The stored document is unavailable.');

        return Storage::disk('local')->download($document->stored_path, $document->original_name);
    }

    private function saveForm(LeaveApplication $application, SaveLeaveApplicationRequest $request, $employee, WorkingDayCalculator $calculator): void
    {
        $data = $request->validated();
        $application->fill([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'office_snapshot' => $employee->department->name,
            'position_snapshot' => $employee->position?->title,
            'salary_snapshot' => $employee->monthly_salary,
            'last_name_snapshot' => $employee->last_name,
            'first_name_snapshot' => $employee->first_name,
            'middle_name_snapshot' => $employee->middle_name,
            'filed_at' => today(),
            'other_leave_type' => $data['other_leave_type'] ?? null,
            'leave_detail_category' => $data['leave_detail_category'] ?? null,
            'leave_detail' => $data['leave_detail'] ?? null,
            'destination' => $data['destination'] ?? null,
            'additional_details' => $data['additional_details'] ?? null,
            'working_days' => isset($data['start_date'], $data['end_date'])
                ? $calculator->count($data['start_date'], $data['end_date'])
                : ($data['working_days'] ?? null),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'commutation_requested' => isset($data['commutation_requested']) ? (bool) $data['commutation_requested'] : null,
            'applicant_acknowledgment' => $data['applicant_acknowledgment'] ?? null,
        ]);
    }

    private function assertNoOverlap(LeaveApplication $application): void
    {
        $overlap = LeaveApplication::query()
            ->where('employee_id', $application->employee_id)
            ->where('id', '!=', $application->id)
            ->whereNotIn('status', ['Draft', 'Cancelled', 'Disapproved'])
            ->whereDate('start_date', '<=', $application->end_date)
            ->whereDate('end_date', '>=', $application->start_date)
            ->exists();
        abort_unless(! $overlap, 422, 'An active application already overlaps these dates.');
    }

    private function saveDocuments(SaveLeaveApplicationRequest $request, LeaveApplication $application): void
    {
        $type = $application->leaveType;
        foreach ($request->file('documents', []) as $index => $file) {
            $path = $file->store('leave-documents/'.$application->id, 'local');
            LeaveApplicationDocument::create([
                'leave_application_id' => $application->id,
                'uploaded_by' => $request->user()->id,
                'document_type' => $request->input("document_types.{$index}"),
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'is_sensitive' => $type?->is_sensitive ?? false,
            ]);
        }
    }
}
