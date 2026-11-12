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
            $application = LeaveApplication::query()->lockForUpdate()->findOrFail($application->id);
            $from = $application->status;
            $action = $data['action'];

            if ($user->isOfficeHead()) {
                abort_unless(
                    in_array($from, ['Under Office Head Review', 'Under Alternate Office Head Review'], true)
                        && ($application->office_head_reviewer_id
                            ? $application->office_head_reviewer_id === $user->id
                            : $application->employee->department->head_user_id === $user->id),
                    409,
                    'This application is no longer awaiting your authorized department review.'
                );
                $nextStatus = $data['action'] === 'return' ? 'Returned for Correction' : 'Under HRMO Verification';
                $historyStatus = $data['action'] === 'return' ? 'Returned for Correction' : 'Endorsed';
                $this->recordHistory($application, $user, 'recommendation', $from, $historyStatus, $data['remarks'] ?? null);
                $application->recommendation = $data['recommendation'];
                $application->recommendation_remarks = $data['remarks'] ?? null;
                $application->recommended_by = $user->id;
                $application->recommended_at = now();
                $application->status = $nextStatus;
            } elseif ($user->canFinalApprove()
                && $from === 'Under Alternate Office Head Review'
                && $application->office_head_reviewer_id === $user->id) {
                if ($action === 'return') {
                    $application->status = 'Returned for Correction';
                    $this->recordHistory($application, $user, 'alternate_review_returned', $from, $application->status, $data['remarks']);
                } elseif (in_array($action, ['approve', 'disapprove'], true)) {
                    if ($action === 'approve') {
                        $withPay = (float) $data['days_with_pay'];
                        $withoutPay = (float) ($data['days_without_pay'] ?? 0);
                        if (abs(($withPay + $withoutPay) - (float) $application->working_days) > 0.001) {
                            throw ValidationException::withMessages([
                                'days_with_pay' => 'Paid and unpaid days must equal the requested working days. HRMO must verify the same total before this alternate decision can be finalized.',
                            ]);
                        }
                        $application->alternate_decision_days_with_pay = $withPay;
                        $application->alternate_decision_days_without_pay = $withoutPay;
                        $application->alternate_decision_others = $data['approval_others'] ?? null;
                    } else {
                        $application->alternate_decision_reason = $data['reason'];
                    }
                    $application->alternate_decision_by = $user->id;
                    $application->alternate_decision_action = $action;
                    $application->alternate_decision_remarks = $data['remarks'] ?? null;
                    $application->alternate_decision_at = now();
                    $application->status = 'Under HRMO Verification';
                    $this->recordHistory(
                        $application,
                        $user,
                        'alternate_final_'.$action.'_recorded',
                        $from,
                        $application->status,
                        $data['remarks'] ?? $data['reason'] ?? null
                    );
                } else {
                    abort(422, 'Invalid alternate decision.');
                }
            } elseif ($user->isHrmo()) {
                abort_unless(
                    $from === 'Under HRMO Verification' && in_array($action, ['verify', 'return'], true),
                    409,
                    'This application is not awaiting HRMO verification.'
                );
                if ($action === 'return') {
                    $application->status = 'Returned for Correction';
                    $this->recordHistory($application, $user, 'hrmo_returned_for_correction', $from, $application->status, $data['remarks']);
                } else {
                    $this->assertRequiredDocumentsSatisfied($application, $data);
                    $application->verified_working_days = $data['verified_working_days'];
                    $application->credit_certification = $this->creditSnapshot($application);
                    $application->certified_by = $user->id;
                    $application->certified_at = now();
                    $application->certifier_position = $data['certifier_position'];
                    $application->certifier_signature = $data['certifier_signature'];
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
                    if ($application->alternate_decision_by) {
                        $alternate = $application->alternateDecisionMaker;
                        abort_unless($alternate?->canFinalApprove(), 409, 'The recorded alternate final approver is no longer authorized.');
                        if ($application->alternate_decision_action === 'approve') {
                            $withPay = (float) $application->alternate_decision_days_with_pay;
                            $withoutPay = (float) $application->alternate_decision_days_without_pay;
                            if (abs(($withPay + $withoutPay) - (float) $application->verified_working_days) > 0.001) {
                                throw ValidationException::withMessages([
                                    'verified_working_days' => 'The verified days differ from the alternate approver’s decision. The decision cannot be finalized; return the application for correction and a new authorized review.',
                                ]);
                            }
                            $this->approve($application, $alternate, [
                                'days_with_pay' => $withPay,
                                'days_without_pay' => $withoutPay,
                                'approval_others' => $application->alternate_decision_others,
                                'remarks' => $application->alternate_decision_remarks,
                            ]);
                            $application->status = 'Approved';
                        } else {
                            abort_unless($application->alternate_decision_action === 'disapprove', 409, 'The stored alternate decision is invalid.');
                            $application->status = 'Disapproved';
                            $application->disapproval_reason = $application->alternate_decision_reason;
                            $application->decision_remarks = $application->alternate_decision_remarks;
                        }
                        $application->decided_by = $alternate->id;
                        $application->decided_at = $application->alternate_decision_at;
                        $this->recordHistory($application, $user, 'verified_and_finalized_alternate_decision', $from, $application->status, $remarks);
                    } else {
                        $application->status = 'Pending Final Approval';
                        $this->recordHistory($application, $user, 'verified', $from, $application->status, $remarks);
                    }
                }
            } elseif ($user->canFinalApprove()) {
                abort_unless($from === 'Pending Final Approval', 409, 'This application is not awaiting final approval.');
                if ($action === 'approve') {
                    $this->approve($application, $user, $data);
                    $application->status = 'Approved';
                } elseif ($action === 'disapprove') {
                    $application->status = 'Disapproved';
                    $application->disapproval_reason = $data['reason'];
                    $application->decision_remarks = $data['remarks'] ?? null;
                } else {
                    abort(422, 'Invalid final decision.');
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

            $credit->used_credits = (float) $credit->used_credits + $withPay;
            $credit->save();
            LeaveCreditTransaction::create([
                'leave_credit_id' => $credit->id,
                'leave_application_id' => $application->id,
                'created_by' => $user->id,
                'type' => 'used',
                'amount' => $withPay,
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

    private function assertRequiredDocumentsSatisfied(LeaveApplication $application, array $data): void
    {
        $required = $application->leaveType->required_documents ?? [];
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

            return;
        }

        if ($application->status === 'Under HRMO Verification') {
            $application->employee->user?->notify(new LeaveApplicationUpdated(
                $application,
                'Your leave application #'.$application->id.' was endorsed for HRMO verification.'
            ));
            User::where('role', 'hrmo')->get()->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' is ready for HRMO verification.')
            ));

            return;
        }

        if ($application->status === 'Pending Final Approval') {
            User::where(fn ($users) => $users
                ->where('role', 'mayor')
                ->orWhere(fn ($legacy) => $legacy->where('role', 'admin')->where('can_final_approve', true)))
                ->get()
                ->each(fn (User $user) => $user->notify(
                new LeaveApplicationUpdated($application, 'Leave application #'.$application->id.' is pending final approval.')
            ));
        }
    }
}
