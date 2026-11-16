<?php

namespace App\Services;

use App\Models\LeaveApplication;
use App\Models\LeaveApprovalHistory;
use App\Models\LeaveCredit;
use App\Models\LeaveCreditTransaction;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveWorkflowService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function act(LeaveApplication $application, User $user, array $data): void
    {
        DB::transaction(function () use ($application, $user, $data) {
            $application = LeaveApplication::query()
                ->with(['employee.user', 'employee.department', 'leaveType'])
                ->lockForUpdate()
                ->findOrFail($application->id);
            abort_if(
                (int) $application->employee->user_id === (int) $user->id,
                403,
                'You cannot review, certify, or decide your own leave application.'
            );
            $from = $application->status;
            $action = $data['action'];

            if ($action === 'assign_hr_reviewer') {
                abort_unless($user->isAdministrator() && $from === 'Awaiting Alternate HR Reviewer Assignment', 409);
                $reviewer = User::query()->lockForUpdate()->findOrFail($data['assigned_hr_reviewer_id']);
                abort_unless($reviewer->is_active && $reviewer->isAdministrator(), 422, 'Choose an active Admin (HR Office) reviewer.');
                abort_if($reviewer->id === $application->employee->user_id, 422, 'The applicant cannot be assigned as their own HR reviewer.');
                $application->assigned_hr_reviewer_id = $reviewer->id;
                $application->status = 'Under HRMO Verification';
                $this->recordHistory($application, $user, 'alternate_hr_reviewer_assigned', $from, $application->status, $data['remarks'] ?? null);
            } elseif ($action === 'assign_final_approver') {
                abort_unless($user->isAdministrator() && $from === 'Awaiting Authorized Final Approver Assignment', 409);
                $approver = User::query()->lockForUpdate()->findOrFail($data['assigned_final_approver_id']);
                abort_unless($approver->canApproveMayorLeave(), 422, 'Choose an active Admin explicitly designated to decide a Mayor’s personal leave.');
                abort_unless($application->employee->user?->isMayor(), 422, 'Only a Mayor’s personal application can use the separate authorized final approver route.');
                $application->assigned_final_approver_id = $approver->id;
                $application->status = 'Pending Authorized Final Approval';
                $this->recordHistory($application, $user, 'mayor_leave_approver_assigned', $from, $application->status, $data['remarks'] ?? null);
            } elseif ($user->isOfficeHead()
                || ($user->isMayor() && $from === 'Under Alternate Office Head Review')) {
                abort_unless(
                    in_array($from, ['Under Office Head Review', 'Under Alternate Office Head Review'], true)
                        && $application->office_head_reviewer_id === $user->id
                        && in_array($action, ['endorse', 'return'], true),
                    409,
                    'This application is no longer awaiting your authorized department review.'
                );
                $application->status = $action === 'return' ? 'Returned for Correction' : 'Under HRMO Verification';
                $application->recommendation = $action === 'return' ? 'Returned for correction' : $data['recommendation'];
                $application->recommendation_remarks = $data['remarks'] ?? null;
                $application->recommended_by = $user->id;
                $application->recommended_at = now();
                $this->recordHistory(
                    $application,
                    $user,
                    $user->isMayor() ? 'mayor_alternate_endorsement' : 'recommendation',
                    $from,
                    $application->status,
                    $data['remarks'] ?? null
                );
            } elseif ($user->isHrmo()
                && ! ($user->canApproveMayorLeave() && $from === 'Pending Authorized Final Approval')) {
                abort_unless($from === 'Under HRMO Verification' && in_array($action, ['verify', 'return'], true),
                    409,
                    'This application is not awaiting HR Office verification.'
                );
                abort_unless(
                    ! $application->assigned_hr_reviewer_id || $application->assigned_hr_reviewer_id === $user->id,
                    403,
                    'This application is assigned to another authorized HR reviewer.'
                );
                if ($application->employee->user?->isAdministrator()) {
                    abort_unless($application->assigned_hr_reviewer_id === $user->id, 403,
                        'An Admin applicant’s leave must be certified by the separately assigned alternate HR reviewer.');
                }
                if ($action === 'return') {
                    $application->status = 'Returned for Correction';
                    $this->recordHistory($application, $user, 'hrmo_returned_for_correction', $from, $application->status, $data['remarks']);
                } else {
                    $this->assertRequiredDocumentsSatisfied($application, $data);
                    $requiresEligibilityReview = (bool) ($application->leaveType->rules['eligibility_review_required'] ?? false);
                    if ($requiresEligibilityReview && empty($data['eligibility_verified'])) {
                        throw ValidationException::withMessages([
                            'eligibility_verified' => 'Confirm the employee’s eligibility before HRMO verification.',
                        ]);
                    }
                    $application->verified_working_days = $data['verified_working_days'];
                    $application->credit_certification = $this->creditSnapshot($application);
                    $application->certified_by = $user->id;
                    $application->certified_at = now();
                    $application->certifier_position = $data['certifier_position'];
                    $application->certifier_signature = $data['certifier_signature'];
                    $application->eligibility_verified_at = $requiresEligibilityReview ? now() : null;
                    $application->eligibility_verified_by = $requiresEligibilityReview ? $user->id : null;
                    $application->eligibility_remarks = $requiresEligibilityReview ? ($data['eligibility_remarks'] ?? null) : null;
                    $application->hr_remarks = $data['remarks'] ?? null;
                    if (! empty($data['waived_documents'])) {
                        $application->document_waivers = [
                            'documents' => array_values(array_unique($data['waived_documents'])),
                            'justification' => $data['waiver_justification'],
                            'waived_by' => $user->id,
                            'waived_at' => now()->toDateTimeString(),
                        ];
                    }
                    $remarks = $data['remarks'] ?? null;
                    if (! empty($data['waived_documents'])) {
                        $remarks = trim(($remarks ? $remarks."\n" : '').'Document waiver: '.$data['waiver_justification']);
                    }
                    $application->status = 'Pending Final Approval';
                    $this->recordHistory($application, $user, 'verified', $from, $application->status, $remarks);
                }
            } elseif ($user->isMayor() || $user->canApproveMayorLeave()) {
                $isMayorPersonalLeave = $from === 'Pending Authorized Final Approval'
                    && $application->assigned_final_approver_id === $user->id
                    && $user->canApproveMayorLeave();
                abort_unless(
                    ($isMayorPersonalLeave || ($user->isMayor() && $from === 'Pending Final Approval'))
                        && in_array($action, ['approve', 'disapprove', 'return'], true),
                    409,
                    'This application is not assigned to you for an authorized final decision.'
                );
                if ($action === 'approve') {
                    if (($application->leaveType->rules['eligibility_review_required'] ?? false)
                        && ! $application->eligibility_verified_at) {
                        throw ValidationException::withMessages([
                            'eligibility_verified' => 'HRMO must confirm this employee’s eligibility before final approval.',
                        ]);
                    }
                    $this->approve($application, $user, $data);
                    $application->status = 'Approved';
                } elseif ($action === 'disapprove') {
                    $application->status = 'Disapproved';
                    $application->disapproval_reason = $data['reason'];
                    $application->decision_remarks = $data['remarks'] ?? null;
                } else {
                    $application->status = 'Returned for Correction';
                    $application->decision_remarks = $data['remarks'];
                }
                $application->decided_by = $user->id;
                $application->decided_at = now();
                $this->recordHistory($application, $user, $action, $from, $application->status, $data['remarks'] ?? $data['reason'] ?? null);
            } else {
                abort(403);
            }

            $application->save();
            $this->auditLogger->record('leave.'.$action, $application, ['status' => $application->status]);
            $this->notifyNextReviewer($application, $user, $action);
        });
    }

    private function approve(LeaveApplication $application, User $user, array $data): void
    {
        $withPay = (float) $data['days_with_pay'];
        $withoutPay = (float) ($data['days_without_pay'] ?? 0);
        $days = (float) ($application->verified_working_days ?? $application->working_days);

        if (abs(($withPay + $withoutPay) - $days) > 0.001) {
            throw ValidationException::withMessages([
                'days_with_pay' => 'Paid and unpaid days must equal the verified working days.',
            ]);
        }

        if ($application->leaveType->requires_credit && $withPay > 0) {
            $credit = LeaveCredit::query()
                ->where('employee_id', $application->employee_id)
                ->where('leave_type_id', $application->leave_type_id)
                ->lockForUpdate()
                ->first();

            if (! $credit || $credit->balance < $withPay) {
                throw ValidationException::withMessages([
                    'days_with_pay' => 'The employee does not have enough verified leave credits for this decision.',
                ]);
            }

            $previousBalance = $credit->balance;
            $credit->used_credits = (float) $credit->used_credits + $withPay;
            $credit->save();
            LeaveCreditTransaction::create([
                'leave_credit_id' => $credit->id,
                'leave_application_id' => $application->id,
                'created_by' => $user->id,
                'type' => 'used',
                'amount' => -$withPay,
                'previous_balance' => $previousBalance,
                'balance_after' => $credit->balance,
                'effective_date' => now()->toDateString(),
                'remarks' => 'Approved leave application #'.$application->id,
            ]);
        }

        $application->approved_days_with_pay = $withPay;
        $application->approved_days_without_pay = $withoutPay;
        $application->approval_others = $data['approval_others'] ?? null;
        $application->decision_remarks = $data['remarks'] ?? null;
    }

    public function requiredDocumentsFor(LeaveApplication $application): array
    {
        $required = $application->leaveType->required_documents ?? [];
        foreach ($application->leaveType->rules['conditional_required_documents'] ?? [] as $condition) {
            $matches = match ($condition['condition'] ?? null) {
                'minimum_working_days' => (int) $application->working_days >= (int) ($condition['value'] ?? PHP_INT_MAX),
                'minimum_calendar_days' => $application->start_date && $application->end_date
                    && $application->start_date->diffInDays($application->end_date) + 1 >= (int) ($condition['value'] ?? PHP_INT_MAX),
                'policy_detail_equals' => ($application->policy_details[$condition['field'] ?? ''] ?? null) === ($condition['value'] ?? null),
                'policy_detail_in' => in_array(
                    $application->policy_details[$condition['field'] ?? ''] ?? null,
                    $condition['value'] ?? [],
                    true
                ),
                default => false,
            };
            if ($matches && isset($condition['document'])) {
                $required[] = $condition['document'];
            }
        }
        return array_values(array_unique($required));
    }

    private function assertRequiredDocumentsSatisfied(LeaveApplication $application, array $data): void
    {
        $required = $this->requiredDocumentsFor($application);
        if ($required === []) {
            return;
        }

        $uploaded = $application->documents()
            ->whereIn('document_type', $required)
            ->pluck('document_type')
            ->unique()
            ->all();
        $waived = array_values(array_unique($data['waived_documents'] ?? []));
        $missing = array_values(array_diff($required, $uploaded, $waived));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'waived_documents' => 'Before verification, attach or formally waive each required item: '.implode('; ', $missing).'.',
            ]);
        }
    }

    private function creditSnapshot(LeaveApplication $application): array
    {
        $result = ['as_of' => now()->toDateString(), 'vacation' => null, 'sick' => null];
        foreach (['vacation' => 'Vacation', 'sick' => 'Sick'] as $key => $needle) {
            $credit = $application->employee->leaveCredits()
                ->whereHas('leaveType', fn ($query) => $query->where('name', 'like', '%'.$needle.'%'))
                ->with('leaveType')
                ->first();

            if ($credit) {
                $less = $application->leave_type_id === $credit->leave_type_id
                    ? (float) ($application->verified_working_days ?? $application->working_days)
                    : 0;
                $earned = (float) $credit->beginning_balance + (float) $credit->earned_credits + (float) $credit->adjustments;
                $result[$key] = [
                    'leave_type' => $credit->leaveType->name,
                    'total_earned' => $earned,
                    'used_before_application' => (float) $credit->used_credits,
                    'less_this_application' => $less,
                    'balance' => (float) $credit->balance - $less,
                ];
            }
        }

        return $result;
    }

    private function recordHistory(LeaveApplication $application, User $user, string $action, string $from, string $to, ?string $remarks): void
    {
        LeaveApprovalHistory::create([
            'leave_application_id' => $application->id,
            'user_id' => $user->id,
            'role' => $user->role,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'remarks' => $remarks,
        ]);
    }

    private function notifyNextReviewer(LeaveApplication $application, User $actor, string $action): void
    {
        if ($application->status === 'Returned for Correction' || in_array($application->status, ['Approved', 'Disapproved'], true)) {
            $application->employee->user?->notify(new LeaveApplicationUpdated(
                $application,
                'Your leave application #'.$application->id.' was '.$application->status.'.'
            ));
            $reviewerIds = array_filter([
                $application->office_head_reviewer_id,
                $application->certified_by,
            ]);
            User::query()->whereIn('id', $reviewerIds)->whereKeyNot($actor->id)->get()->each(fn (User $reviewer) => $reviewer->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' is now '.$application->status.'.')
            ));

            return;
        }

        if ($application->status === 'Under HRMO Verification') {
            $application->employee->user?->notify(new LeaveApplicationUpdated(
                $application,
                'Your leave application #'.$application->id.' was endorsed for HRMO verification.'
            ));
            $reviewers = $application->assigned_hr_reviewer_id
                ? User::whereKey($application->assigned_hr_reviewer_id)->get()
                : User::where('role', 'admin')->where('is_active', true)->get();
            $reviewers->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' is ready for HRMO verification.')
            ));

            return;
        }

        if ($application->status === 'Pending Final Approval') {
            User::where('role', 'mayor')->where('is_active', true)
                ->get()
                ->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' is pending final approval.')
            ));
            return;
        }

        if ($application->status === 'Pending Authorized Final Approval' && $application->assigned_final_approver_id) {
            User::whereKey($application->assigned_final_approver_id)->get()->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Mayor leave application #'.$application->id.' is assigned for your authorized final decision.')
            ));
            return;
        }

        if (in_array($application->status, [
            'Awaiting Alternate HR Reviewer Assignment',
            'Awaiting Authorized Final Approver Assignment',
        ], true)) {
            User::where('role', 'admin')->where('is_active', true)->get()->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' needs an authorized reviewer assignment.')
            ));
        }
    }
}
