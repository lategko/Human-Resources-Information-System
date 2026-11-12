<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLeaveApplicationRequest;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDocument;
use App\Models\LeaveApprovalHistory;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\OfficeHeadAvailability;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use App\Services\AuditLogger;
use App\Services\WorkingDayCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class LeaveApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $query = LeaveApplication::with(['employee.department', 'leaveType']);
        $user = $request->user();
        if ($user->isEmployee()) {
            $query->where('employee_id', $user->employee?->id ?? 0);
        } elseif ($user->isOfficeHead()) {
            $query->where(function ($applications) use ($user) {
                $applications->where('office_head_reviewer_id', $user->id)
                    ->orWhereHas('employee.department', fn ($department) => $department->where('head_user_id', $user->id));
            });
        } elseif ($user->canFinalApprove() && ! $user->isAdministrator()) {
            $query->where(function ($applications) use ($user) {
                $applications->where('status', 'Pending Final Approval')
                    ->orWhere(function ($alternate) use ($user) {
                        $alternate->where('status', 'Under Alternate Office Head Review')
                            ->where('office_head_reviewer_id', $user->id);
                    })
                    ->orWhere('decided_by', $user->id)
                    ->orWhere('alternate_decision_by', $user->id);
            });
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
        $submitted = $request->input('intent') === 'submit';
        $storedPaths = [];
        try {
            $application = DB::transaction(function () use ($request, $employee, $calculator, $auditLogger, $submitted, &$storedPaths) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                $application = new LeaveApplication;
                $this->saveForm($application, $request, $employee, $calculator);
                $application->save();
                $application->status = $submitted ? 'Submitted' : 'Draft';
                if ($submitted) {
                    $this->assertNoOverlap($application);
                    $route = $this->resolveOfficeHeadRoute($employee, $application);
                    $application->office_head_reviewer_id = $route['reviewer_id'];
                    $application->office_head_routing_reason = $route['reason'];
                }
                $application->save();

                LeaveApprovalHistory::create([
                    'leave_application_id' => $application->id,
                    'user_id' => $request->user()->id,
                    'role' => $request->user()->role,
                    'action' => $application->status === 'Submitted' ? 'submitted' : 'draft_saved',
                    'to_status' => $application->status,
                ]);
                if ($submitted) {
                    $nextStatus = $route['status'];
                    LeaveApprovalHistory::create([
                        'leave_application_id' => $application->id,
                        'user_id' => $request->user()->id,
                        'role' => $request->user()->role,
                        'action' => $route['action'],
                        'from_status' => 'Submitted',
                        'to_status' => $nextStatus,
                    ]);
                    $application->status = $nextStatus;
                    $application->save();
                }
                $this->saveDocuments($request, $application, $storedPaths);
                $auditLogger->record($submitted ? 'leave.submitted' : 'leave.draft_saved', $application, [
                    'status' => $application->status,
                    'reviewer_id' => $application->office_head_reviewer_id,
                ]);

                return $application;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
        if ($submitted) {
            $application->load('employee', 'officeHeadReviewer');
            $application->officeHeadReviewer?->notify(new LeaveApplicationUpdated(
                $application,
                'A new leave application from '.$application->employee->full_name.' is awaiting your review.'
            ));
        }

        return redirect()->route('leave.show', $application)->with('success', $submitted
            ? 'Your leave application has been submitted for review.'
            : 'Your draft has been saved.');
    }

    public function show(LeaveApplication $application): View
    {
        $this->authorize('view', $application);
        $application->load([
            'employee.department', 'employee.position', 'leaveType', 'documents', 'history.user',
            'officeHeadReviewer', 'alternateDecisionMaker',
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

        $submitted = $request->input('intent') === 'submit';
        $storedPaths = [];
        try {
            $application = DB::transaction(function () use ($request, $application, $employee, $calculator, $auditLogger, $submitted, &$storedPaths) {
                $application = LeaveApplication::query()->lockForUpdate()->findOrFail($application->id);
                abort_unless(
                    $application->employee_id === $employee->id
                        && in_array($application->status, ['Draft', 'Returned for Correction'], true),
                    409,
                    'This leave application is no longer available for editing.'
                );
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
                $previous = $application->status;
                $this->saveForm($application, $request, $employee, $calculator);
                if ($submitted) {
                    $this->assertNoOverlap($application);
                    $route = $this->resolveOfficeHeadRoute($employee, $application);
                    $application->fill([
                    'verified_working_days' => null,
                    'credit_certification' => null,
                    'certified_by' => null,
                    'certified_at' => null,
                    'certifier_position' => null,
                    'certifier_signature' => null,
                    'hr_remarks' => null,
                    'document_waivers' => null,
                    'recommendation' => null,
                    'recommendation_remarks' => null,
                    'recommended_by' => null,
                    'recommended_at' => null,
                    'approved_days_with_pay' => null,
                    'approved_days_without_pay' => null,
                    'approval_others' => null,
                    'disapproval_reason' => null,
                    'decision_remarks' => null,
                    'decided_by' => null,
                    'decided_at' => null,
                    'alternate_decision_by' => null,
                    'alternate_decision_action' => null,
                    'alternate_decision_days_with_pay' => null,
                    'alternate_decision_days_without_pay' => null,
                    'alternate_decision_others' => null,
                    'alternate_decision_reason' => null,
                    'alternate_decision_remarks' => null,
                    'alternate_decision_at' => null,
                    ]);
                    $application->office_head_reviewer_id = $route['reviewer_id'];
                    $application->office_head_routing_reason = $route['reason'];
                    $application->status = $route['status'];
                    LeaveApprovalHistory::create([
                        'leave_application_id' => $application->id,
                        'user_id' => $request->user()->id,
                        'role' => $request->user()->role,
                        'action' => 'resubmitted',
                        'from_status' => $previous,
                        'to_status' => $route['status'],
                    ]);
                }
                $application->save();
                $this->saveDocuments($request, $application, $storedPaths);
                $auditLogger->record('leave.updated', $application, [
                    'status' => $application->status,
                    'reviewer_id' => $application->office_head_reviewer_id,
                ]);

                return $application;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
        if ($submitted) {
            $application->load('employee', 'officeHeadReviewer');
            $application->officeHeadReviewer?->notify(new LeaveApplicationUpdated(
                $application,
                'A leave application from '.$application->employee->full_name.' is awaiting your review.'
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

    private function resolveOfficeHeadRoute($employee, LeaveApplication $application): array
    {
        $department = $employee->department()->with('head')->firstOrFail();
        $head = $department->head;
        $availability = $head
            ? OfficeHeadAvailability::query()
                ->where('department_id', $department->id)
                ->where('office_head_user_id', $head->id)
                ->where('availability_status', '!=', 'available')
                ->whereDate('start_date', '<=', $application->end_date)
                ->whereDate('end_date', '>=', $application->start_date)
                ->latest('start_date')
                ->latest('id')
                ->first()
            : null;

        if ($head?->is_active && $head->isOfficeHead()
            && (! $availability || $availability->availability_status === 'available')) {
            return [
                'reviewer_id' => $head->id,
                'status' => 'Under Office Head Review',
                'action' => 'queued_for_office_head_review',
                'reason' => null,
            ];
        }

        $alternate = $availability?->alternate;
        if (! $alternate?->is_active || (! $alternate->isOfficeHead() && ! $alternate->canFinalApprove())) {
            $finalApprovers = User::query()->where('is_active', true)
                ->where(fn ($users) => $users
                    ->where('role', 'mayor')
                    ->orWhere(fn ($legacy) => $legacy->where('role', 'admin')->where('can_final_approve', true)))
                ->orderBy('id')
                ->limit(2)
                ->get();
            if ($finalApprovers->count() !== 1) {
                throw ValidationException::withMessages([
                    'availability' => 'The Office Head is unavailable and there is no single designated final approver. HRMO or an administrator must assign an authorized alternate before this application can be submitted.',
                ]);
            }
            $alternate = $finalApprovers->first();
        }

        $reason = $availability
            ? ucfirst(str_replace('_', ' ', $availability->availability_status)).': '.$availability->reason
            : ($head ? 'The assigned Office Head account is inactive.' : 'No Office Head is assigned to this department.');

        return [
            'reviewer_id' => $alternate->id,
            'status' => 'Under Alternate Office Head Review',
            'action' => 'queued_for_alternate_office_head_review',
            'reason' => $reason,
        ];
    }

    private function saveDocuments(SaveLeaveApplicationRequest $request, LeaveApplication $application, array &$storedPaths): void
    {
        $type = $application->leaveType;
        foreach ($request->file('documents', []) as $index => $file) {
            $path = $file->store('leave-documents/'.$application->id, 'local');
            if (! $path) {
                throw new \RuntimeException('A supporting document could not be stored. The leave transaction was rolled back.');
            }
            $storedPaths[] = $path;
            LeaveApplicationDocument::create([
                'leave_application_id' => $application->id,
                'uploaded_by' => $request->user()->id,
                'document_type' => $request->input("document_types.{$index}"),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $path,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'is_sensitive' => $type?->is_sensitive ?? false,
            ]);
        }
    }
}
