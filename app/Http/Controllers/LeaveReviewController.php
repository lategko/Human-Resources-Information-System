<?php

namespace App\Http\Controllers;

use App\Models\LeaveApplication;
use App\Services\LeaveWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveReviewController extends Controller
{
    public function __invoke(Request $request, LeaveApplication $application, LeaveWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('review', $application);
        $user = $request->user();
        $isPendingMayorLeaveDecision = $application->status === 'Pending Authorized Final Approval'
            && $application->assigned_final_approver_id === $user->id
            && $user->canApproveMayorLeave();
        $isAlternateOfficeHeadReview = $application->status === 'Under Alternate Office Head Review'
            && $application->office_head_reviewer_id === $user->id
            && ($user->isOfficeHead() || $user->isMayor());
        $waivableDocuments = array_values(array_unique(array_merge(
            $application->leaveType->required_documents ?? [],
            collect($application->leaveType->rules['conditional_required_documents'] ?? [])
                ->pluck('document')->filter()->all()
        )));
        $rules = match (true) {
            $user->isAdministrator() && $application->status === 'Awaiting Alternate HR Reviewer Assignment' => [
                'action' => ['required', Rule::in(['assign_hr_reviewer'])],
                'assigned_hr_reviewer_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'admin')->where('is_active', true)],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ],
            $user->isAdministrator() && $application->status === 'Awaiting Authorized Final Approver Assignment' => [
                'action' => ['required', Rule::in(['assign_final_approver'])],
                'assigned_final_approver_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'admin')->where('can_final_approve', true)->where('is_active', true)],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ],
            $isAlternateOfficeHeadReview || $user->isOfficeHead() => [
                'action' => ['required', 'in:endorse,return'],
                'recommendation' => ['required_if:action,endorse', 'nullable', 'in:For approval,For disapproval'],
                'remarks' => ['required_if:action,return', 'required_if:recommendation,For disapproval', 'nullable', 'string', 'max:2000'],
            ],
            $user->isMayor() || $isPendingMayorLeaveDecision => [
                'action' => ['required', 'in:approve,disapprove,return'],
                'days_with_pay' => ['required_if:action,approve', 'numeric', 'min:0', 'max:365'],
                'days_without_pay' => ['nullable', 'numeric', 'min:0', 'max:365'],
                'approval_others' => ['nullable', 'string', 'max:500'],
                'reason' => ['required_if:action,disapprove', 'nullable', 'string', 'max:2000'],
                'remarks' => ['required_if:action,return', 'nullable', 'string', 'max:2000'],
            ],
            $user->isHrmo() => [
                'action' => ['required', 'in:verify,return'],
                'verified_working_days' => ['required_if:action,verify', 'nullable', 'integer', 'min:1', 'max:365'],
                'certifier_position' => ['required_if:action,verify', 'nullable', 'string', 'max:120'],
                'certifier_signature' => ['required_if:action,verify', 'nullable', 'string', 'max:120'],
                'remarks' => ['required_if:action,return', 'nullable', 'string', 'max:2000'],
                'eligibility_verified' => [
                    Rule::requiredIf(fn () => $request->input('action') === 'verify'
                        && (bool) ($application->leaveType->rules['eligibility_review_required'] ?? false)),
                    'boolean',
                ],
                'eligibility_remarks' => ['nullable', 'string', 'max:2000'],
                'waived_documents' => ['nullable', 'array', 'max:20'],
                'waived_documents.*' => ['string', 'max:200', Rule::in($waivableDocuments)],
                'waiver_justification' => ['required_with:waived_documents', 'nullable', 'string', 'max:2000'],
            ],
            default => [],
        };
        abort_if($rules === [], 403);
        $data = $request->validate($rules);

        $workflow->act($application, $user, $data);
        $reviewerLabel = $user->isMayor() ? 'authorized alternate reviewer' : 'Office Head';
        $message = match ($data['action']) {
            'assign_hr_reviewer' => "An alternate HR reviewer has been assigned to leave application #{$application->id}, which is now queued for HRMO verification.",
            'assign_final_approver' => "The designated final approver has been assigned to Mayor leave application #{$application->id}.",
            'endorse' => $data['recommendation'] === 'For disapproval'
                ? "The {$reviewerLabel} has recommended disapproval for leave application #{$application->id}; the authorized final reviewer will make the separate decision."
                : "The {$reviewerLabel} has recommended approval for leave application #{$application->id}; final approval is still pending.",
            'verify' => "HRMO verification for leave application #{$application->id} is complete; it has been sent to the assigned Office Head or authorized alternate.",
            'approve' => "Leave application #{$application->id} has been approved by the authorized final reviewer.",
            'disapprove' => "Leave application #{$application->id} has been disapproved, and the reason has been recorded.",
            'return' => "Leave application #{$application->id} has been returned to the employee for correction.",
        };

        return redirect()->route('leave.show', $application)->with('success', $message);
    }
}
