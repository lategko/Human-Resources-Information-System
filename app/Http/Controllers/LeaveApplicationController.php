<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveLeaveApplicationRequest;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationDocument;
use App\Models\LeaveApprovalHistory;
use App\Models\LeaveType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use App\Services\AuditLogger;
use App\Services\LeaveWorkflowService;
use App\Services\WorkingDayCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $employeeId = $user->employee?->id;
        if ($request->boolean('mine') || $user->isEmployee()) {
            $query->where('employee_id', $employeeId ?? 0);
        } elseif ($user->isOfficeHead()) {
            $query->where(function ($applications) use ($user) {
                $applications->where('employee_id', $user->employee?->id ?? 0)
                    ->orWhere(function ($assigned) use ($user) {
                        $assigned->where('office_head_reviewer_id', $user->id)
                            ->where(function ($workflow) use ($user) {
                                $workflow->where('status', '!=', 'Under Office Head Review')
                                    ->orWhereHas('employee.department', fn ($department) => $department
                                        ->where('head_user_id', $user->id));
                            });
                    })
                    ->orWhereHas('employee.department', fn ($department) => $department->where('head_user_id', $user->id));
            });
        } elseif ($user->isMayor()) {
            $query->where(function ($applications) use ($user) {
                $applications->where('employee_id', $user->employee?->id ?? 0)
                    ->orWhere('status', 'Pending Final Approval')
                    ->orWhere(function ($alternate) use ($user) {
                        $alternate->where('status', 'Under Alternate Office Head Review')
                            ->where('office_head_reviewer_id', $user->id);
                    })
                    ->orWhere('decided_by', $user->id);
            });
        } elseif ($user->canApproveMayorLeave() && ! $user->canManageHr()) {
            $query->where(function ($applications) use ($user) {
                $applications->where('employee_id', $user->employee?->id ?? 0)
                    ->orWhere(fn ($assigned) => $assigned
                        ->where('status', 'Pending Authorized Final Approval')
                        ->where('assigned_final_approver_id', $user->id));
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
        $employee = $request->user()->employee?->load(['department', 'position']);
        abort_unless($employee, 403, 'Link your account to an employee record before filing personal leave.');

        return view('leave.form', [
            'application' => new LeaveApplication([
                'filed_at' => today(),
                'office_snapshot' => $employee->department?->name ?? 'Department not assigned',
                'position_snapshot' => $employee->position?->title,
                'salary_snapshot' => $employee->monthly_salary,
                'last_name_snapshot' => $employee->last_name,
                'first_name_snapshot' => $employee->first_name,
                'middle_name_snapshot' => $employee->middle_name,
            ]),
            'employee' => $employee,
            'leaveCredits' => $employee->leaveCredits()->with('leaveType')->get(),
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
                if ($submitted) {
                    $this->assertCreditAvailable($application);
                    $this->assertPolicyEntitlementAvailable($application);
                }
                $application->status = $submitted ? 'Submitted' : 'Draft';
                if ($submitted) {
                    $this->assertNoOverlap($application);
                    $route = $this->resolveSubmissionRoute($request->user(), $employee, $application);
                    $application->office_head_reviewer_id = $route['reviewer_id'];
                    $application->office_head_routing_reason = $route['reason'];
                    $application->assigned_hr_reviewer_id = $route['hr_reviewer_id'];
                    $application->assigned_final_approver_id = $route['final_approver_id'];
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
            $this->notifySubmissionReviewer($application);
        }

        return redirect()->route('leave.show', $application)->with('success', $submitted
            ? 'Your leave application has been submitted for review.'
            : 'Your draft has been saved.');
    }

    public function show(LeaveApplication $application, LeaveWorkflowService $workflow): View
    {
        $this->authorize('view', $application);
        $application->load([
            'employee.department', 'employee.position', 'leaveType', 'documents', 'history.user',
            'officeHeadReviewer', 'alternateDecisionMaker', 'assignedHrReviewer', 'assignedFinalApprover', 'eligibilityVerifier',
        ]);
        $sensitiveDetailsVisible = ! $application->leaveType->is_sensitive
            || auth()->user()->canManageHr()
            || auth()->user()->isAdministrator()
            || auth()->user()->employee?->id === $application->employee_id;
        $requiredDocuments = $workflow->requiredDocumentsFor($application);

        return view('leave.show', compact('application', 'sensitiveDetailsVisible', 'requiredDocuments'));
    }

    public function edit(LeaveApplication $application): View
    {
        $this->authorize('update', $application);
        $employee = $application->employee->load(['department', 'position']);

        return view('leave.form', [
            'application' => $application->load('leaveType'),
            'employee' => $employee,
            'leaveCredits' => $employee->leaveCredits()->with('leaveType')->get(),
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
                    $this->assertCreditAvailable($application);
                }
                if ($submitted) {
                    $this->assertNoOverlap($application);
                    $route = $this->resolveSubmissionRoute($request->user(), $employee, $application);
                    $application->fill([
                        'verified_working_days' => null,
                        'credit_certification' => null,
                        'certified_by' => null,
                        'certified_at' => null,
                        'eligibility_verified_at' => null,
                        'eligibility_verified_by' => null,
                        'eligibility_remarks' => null,
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
                    $application->assigned_hr_reviewer_id = $route['hr_reviewer_id'];
                    $application->assigned_final_approver_id = $route['final_approver_id'];
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
            $this->notifySubmissionReviewer($application);
        }

        return redirect()->route('leave.show', $application)->with('success', 'Your application has been saved.');
    }

    public function cancel(Request $request, LeaveApplication $application, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('cancel', $application);
        DB::transaction(function () use ($request, $application, $auditLogger) {
            $locked = LeaveApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_unless(in_array($locked->status, [
                'Draft',
                'Submitted',
                'Under HRMO Verification',
                'Under Office Head Review',
                'Returned for Correction',
            ], true), 409);
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
        $workingDays = isset($data['start_date'], $data['end_date'])
            ? $calculator->count($data['start_date'], $data['end_date'])
            : ($data['working_days'] ?? null);
        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        if ($request->input('intent') === 'submit'
            && ! in_array($leaveType->code, ['MONETIZATION', 'TERMINAL'], true)
            && (float) $workingDays < 1) {
            throw ValidationException::withMessages([
                'start_date' => 'The selected dates contain no working days in the configured work calendar.',
            ]);
        }

        $application->fill([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'office_snapshot' => $employee->department?->name ?? 'Department not assigned',
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
            'policy_details' => $data['policy_details'] ?? [],
            'working_days' => $workingDays,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'commutation_requested' => isset($data['commutation_requested']) ? (bool) $data['commutation_requested'] : null,
            'applicant_acknowledgment' => $data['applicant_acknowledgment'] ?? null,
        ]);
    }

    private function assertCreditAvailable(LeaveApplication $application): void
    {
        $type = $application->leaveType;
        if (! $type->requires_credit) {
            return;
        }

        $credit = $application->employee->leaveCredits()
            ->where('leave_type_id', $type->id)
            ->lockForUpdate()
            ->first();
        $pendingDays = LeaveApplication::query()
            ->where('employee_id', $application->employee_id)
            ->where('leave_type_id', $type->id)
            ->where('id', '!=', $application->id)
            ->whereNotIn('status', ['Draft', 'Cancelled', 'Disapproved', 'Approved'])
            ->selectRaw('COALESCE(SUM(COALESCE(approved_days_with_pay, verified_working_days, working_days)), 0) as reserved_days')
            ->value('reserved_days');
        if (! $credit || $credit->balance - (float) $pendingDays < (float) $application->working_days) {
            throw ValidationException::withMessages([
                'working_days' => 'The available '.$type->name.' balance, after other pending applications, is insufficient for the requested working days.',
            ]);
        }
    }

    private function assertPolicyEntitlementAvailable(LeaveApplication $application): void
    {
        $type = $application->leaveType;
        $rules = $type->rules ?? [];
        $details = $application->policy_details ?? [];
        $start = $application->start_date;
        $end = $application->end_date;
        $days = ($rules['entitlement_unit'] ?? 'working_days') === 'calendar_days'
            ? $start->diffInDays($end) + 1
            : (float) $application->working_days;

        if (($rules['entitlement_period'] ?? null) === 'annual' && $start->year !== $end->year) {
            throw ValidationException::withMessages([
                'end_date' => 'An annual leave entitlement request cannot span two calendar years.',
            ]);
        }

        if ($type->code === 'SICK' && $end->gt(today())) {
            throw ValidationException::withMessages([
                'end_date' => 'Sick Leave is filed upon return from the absence and cannot be submitted for future dates.',
            ]);
        }

        $advanceDays = $rules['filing_advance_days'] ?? null;
        if ($type->code === 'SPECIAL_PRIVILEGE' && $advanceDays !== null
            && $start->lt(today()->addDays((int) $advanceDays))
            && ($details['emergency_case'] ?? null) !== 'yes') {
            throw ValidationException::withMessages([
                'policy_details.emergency_case' => 'Special Privilege Leave must be filed at least '.$advanceDays.' days in advance, unless HRMO reviews an emergency exception.',
            ]);
        }

        if (isset($rules['max_entitlement_months'])
            && $end->gt($start->copy()->addMonthsNoOverflow((int) $rules['max_entitlement_months'])->subDay())) {
            throw ValidationException::withMessages([
                'end_date' => 'The requested period exceeds the configured maximum of '.$rules['max_entitlement_months'].' months for '.$type->name.'.',
            ]);
        }

        $occurrenceWindow = $rules['use_within_occurrence_days'] ?? null;
        if ($occurrenceWindow !== null) {
            $occurrenceDate = $details['calamity_occurrence_date'] ?? null;
            if (! $occurrenceDate || ! strtotime($occurrenceDate)) {
                throw ValidationException::withMessages(['policy_details.calamity_occurrence_date' => 'Enter the actual calamity occurrence date.']);
            }
            $occurrence = Carbon::parse($occurrenceDate)->startOfDay();
            if ($start->lt($occurrence) || $start->gt($occurrence->copy()->addDays((int) $occurrenceWindow))) {
                throw ValidationException::withMessages([
                    'start_date' => 'Special Emergency Leave must begin within '.$occurrenceWindow.' days of the actual calamity.',
                ]);
            }
        }

        $annualLimit = $rules['annual_entitlement_days'] ?? null;
        if (($rules['entitlement_period'] ?? null) === 'annual') {
            $annualLimit ??= $rules['entitlement_days'] ?? null;
        }

        if ($annualLimit !== null || isset($rules['maximum_uses_per_year'])) {
            $yearStart = $start->copy()->startOfYear()->toDateString();
            $yearEnd = $start->copy()->endOfYear()->toDateString();
            $priorApplications = LeaveApplication::query()
                ->where('employee_id', $application->employee_id)
                ->where('leave_type_id', $type->id)
                ->where('id', '!=', $application->id)
                ->whereNotIn('status', ['Draft', 'Cancelled', 'Disapproved'])
                ->whereBetween('start_date', [$yearStart, $yearEnd]);

            if (isset($rules['maximum_uses_per_year'])
                && (clone $priorApplications)->count() >= (int) $rules['maximum_uses_per_year']) {
                throw ValidationException::withMessages([
                    'leave_type_id' => 'The annual maximum number of '.$type->name.' applications has already been reached.',
                ]);
            }

            if ($annualLimit !== null) {
                $usedDays = (float) (clone $priorApplications)
                    ->selectRaw('COALESCE(SUM(COALESCE(verified_working_days, working_days)), 0) as used_days')
                    ->value('used_days');
                if ($usedDays + $days > (float) $annualLimit) {
                    throw ValidationException::withMessages([
                        'working_days' => 'This request would exceed the annual '.$type->name.' entitlement of '.$annualLimit.' days.',
                    ]);
                }
            }
        } elseif (isset($rules['entitlement_days']) && $days > (float) $rules['entitlement_days']) {
            throw ValidationException::withMessages([
                'working_days' => 'This request exceeds the configured '.$type->name.' entitlement of '.$rules['entitlement_days'].' days.',
            ]);
        }

        if ($type->code === 'MONETIZATION') {
            $requested = (float) ($details['requested_credit_days'] ?? 0);
            $accumulated = (float) $application->employee->leaveCredits()
                ->whereHas('leaveType', fn ($query) => $query->whereIn('code', ['VACATION', 'SICK']))
                ->get()
                ->sum(fn ($credit) => $credit->balance);
            $minimum = $accumulated * (float) ($rules['minimum_credit_fraction'] ?? 0.5);
            if ($accumulated <= 0 || $requested < $minimum || $requested > $accumulated) {
                throw ValidationException::withMessages([
                    'policy_details.requested_credit_days' => 'The requested amount must be at least the configured minimum and no more than the employee’s accumulated regular leave credits.',
                ]);
            }
        }
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

    private function resolveSubmissionRoute(User $applicant, Employee $employee, LeaveApplication $application): array
    {
        if ((int) $employee->user_id === (int) $applicant->id) {
            if ($applicant->isAdministrator()) {
                $reviewerId = SystemSetting::find('leave_alternate_hr_reviewer_id')?->value;
                $reviewer = $reviewerId
                    ? User::query()->whereKey($reviewerId)->where('is_active', true)->where('role', 'admin')->first()
                    : null;
                $reviewer = $reviewer?->id !== $applicant->id ? $reviewer : null;

                return [
                    'reviewer_id' => null,
                    'hr_reviewer_id' => $reviewer?->id,
                    'final_approver_id' => null,
                    'status' => $reviewer ? 'Under HRMO Verification' : 'Awaiting Alternate HR Reviewer Assignment',
                    'reason' => 'Admin personal leave requires a separately assigned Admin reviewer.',
                    'action' => $reviewer ? 'queued_for_alternate_hr_review' : 'awaiting_alternate_hr_assignment',
                ];
            }

            if ($applicant->isMayor()) {
                $approverId = SystemSetting::find('mayor_leave_approver_id')?->value;
                $approver = $approverId
                    ? User::query()->whereKey($approverId)->where('is_active', true)->where('role', 'admin')
                        ->where('can_final_approve', true)->first()
                    : null;
                $approver = $approver?->id !== $applicant->id ? $approver : null;

                return [
                    'reviewer_id' => null,
                    'hr_reviewer_id' => null,
                    'final_approver_id' => $approver?->id,
                    'status' => 'Under HRMO Verification',
                    'reason' => 'Mayor personal leave requires HRMO certification, Office Head verification, and a separately designated authorized final approver.',
                    'action' => 'queued_for_hrmo_verification',
                ];
            }

            if ($applicant->isOfficeHead()) {
                return [
                    'reviewer_id' => null,
                    'hr_reviewer_id' => null,
                    'final_approver_id' => null,
                    'status' => 'Under HRMO Verification',
                    'reason' => 'Office Head personal leave bypasses self-endorsement and goes directly to HR Office verification.',
                    'action' => 'queued_for_hrmo_verification',
                ];
            }
        }

        return [
            'reviewer_id' => null,
            'hr_reviewer_id' => null,
            'final_approver_id' => null,
            'status' => 'Under HRMO Verification',
            'reason' => null,
            'action' => 'queued_for_hrmo_verification',
        ];
    }

    private function notifySubmissionReviewer(LeaveApplication $application): void
    {
        $application->load('employee', 'officeHeadReviewer', 'assignedHrReviewer', 'assignedFinalApprover');
        $reviewer = $application->officeHeadReviewer
            ?? $application->assignedHrReviewer
            ?? $application->assignedFinalApprover;

        if ($reviewer) {
            $reviewer->notify(new LeaveApplicationUpdated(
                $application,
                'A leave application from '.$application->employee->full_name.' is awaiting your review.'
            ));

            return;
        }

        if ($application->status === 'Under HRMO Verification'
            || str_starts_with($application->status, 'Awaiting')) {
            User::query()->where('role', 'admin')->where('is_active', true)->get()->each(
                fn (User $admin) => $admin->notify(new LeaveApplicationUpdated(
                    $application,
                    $application->status === 'Under HRMO Verification'
                        ? 'A leave application is awaiting HR Office verification.'
                        : 'A leave application needs an authorized reviewer assignment.'
                ))
            );
        }
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
