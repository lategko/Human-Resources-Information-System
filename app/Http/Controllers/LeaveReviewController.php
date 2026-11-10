<?php

namespace App\Http\Controllers;

use App\Models\LeaveApplication;
use App\Services\LeaveWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeaveReviewController extends Controller
{
    public function __invoke(Request $request, LeaveApplication $application, LeaveWorkflowService $workflow): RedirectResponse
    {
        $this->authorize('review', $application);
        $user = $request->user();
        $rules = match (true) {
            $user->isOfficeHead() => [
                'action' => ['required', 'in:endorse,return'],
                'recommendation' => ['required', 'in:For approval,For disapproval'],
                'remarks' => ['required_if:action,return', 'required_if:recommendation,For disapproval', 'nullable', 'string', 'max:2000'],
            ],
            $user->isHrmo() => [
                'action' => ['required', 'in:verify'],
                'verified_working_days' => ['required', 'integer', 'min:1', 'max:365'],
                'certifier_position' => ['required', 'string', 'max:120'],
                'certifier_signature' => ['required', 'string', 'max:120'],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'waived_documents' => ['nullable', 'array', 'max:20'],
                'waived_documents.*' => ['string', 'max:200'],
                'waiver_justification' => ['required_with:waived_documents', 'nullable', 'string', 'max:2000'],
            ],
            $user->canFinalApprove() => [
                'action' => ['required', 'in:approve,disapprove'],
                'days_with_pay' => ['required_if:action,approve', 'numeric', 'min:0', 'max:365'],
                'days_without_pay' => ['nullable', 'numeric', 'min:0', 'max:365'],
                'approval_others' => ['nullable', 'string', 'max:500'],
                'reason' => ['required_if:action,disapprove', 'nullable', 'string', 'max:2000'],
                'remarks' => ['nullable', 'string', 'max:2000'],
            ],
            default => [],
        };
        abort_if($rules === [], 403);
        $data = $request->validate($rules);

        $workflow->act($application, $user, $data);

        return redirect()->route('leave.show', $application)->with('success', 'The leave application review has been recorded.');
    }
}
