@extends('layouts.app')
@section('title', 'Leave application #'.$application->id)
@section('content')
    <div class="page-heading"><div><span class="eyebrow">APPLICATION #{{ str_pad($application->id, 5, '0', STR_PAD_LEFT) }}</span><h1>{{ $application->leaveType->name }}{{ $application->other_leave_type ? ' · '.$application->other_leave_type : '' }}</h1><p>{{ $application->employee->full_name }} <span class="muted-separator">·</span> Filed {{ $application->filed_at?->format('F j, Y') ?? $application->created_at->format('F j, Y') }}</p></div>
        <div class="heading-actions"><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span><a class="button button-outline" href="{{ route('leave.print', $application) }}" target="_blank">Print / Save PDF</a>@can('update', $application)<a class="button button-primary" href="{{ route('leave.edit', $application) }}">Edit application</a>@endcan
            @can('cancel', $application)<form method="POST" action="{{ route('leave.cancel', $application) }}" onsubmit="return confirm('Cancel this leave application? This cannot be undone.')">@csrf<button class="button button-danger" type="submit">Cancel request</button></form>@endcan
        </div>
    </div>
    <div class="application-layout leave-application-layout">
        <div class="application-main">
            <section class="panel form-panel">
                <div class="panel-heading"><div><h2>Application details</h2><p>Employee information snapshot captured when filed.</p></div></div>
                <div class="detail-grid">
                    <div><small>Office / department</small><b>{{ $application->office_snapshot }}</b></div><div><small>Position</small><b>{{ $application->position_snapshot ?? '—' }}</b></div><div><small>Employee</small><b>{{ $application->last_name_snapshot }}, {{ $application->first_name_snapshot }} {{ $application->middle_name_snapshot }}</b></div>                    @if(!auth()->user()->isOfficeHead())<div><small>Salary snapshot</small><b>{{ $application->salary_snapshot ? '₱'.number_format((float)$application->salary_snapshot, 2) : '—' }}</b></div>@endif
                    <div><small>Leave type</small><b>{{ $application->leaveType->name }}{{ $application->other_leave_type ? ' · '.$application->other_leave_type : '' }}</b></div><div><small>Commutation</small><b>{{ $application->commutation_requested === null ? 'Not selected' : ($application->commutation_requested ? 'Requested' : 'Not Requested') }}</b></div>
                    <div><small>Inclusive dates</small><b>{{ $application->start_date?->format('M j, Y') ?? '—' }}{{ $application->end_date?->ne($application->start_date) ? ' – '.$application->end_date?->format('M j, Y') : '' }}</b></div><div><small>Working days</small><b>{{ $application->verified_working_days ?? $application->working_days ?? '—' }}</b></div>
                    @if($application->eligibility_verified_at && !auth()->user()->isOfficeHead())
                        <div class="field-span"><small>HRMO eligibility verification</small><b>Verified by {{ $application->eligibilityVerifier?->name ?? 'authorized HRMO' }} on {{ $application->eligibility_verified_at->format('M j, Y') }}</b>@if($application->eligibility_remarks)<small>{{ $application->eligibility_remarks }}</small>@endif</div>
                    @endif
                    @if($application->officeHeadReviewer)
                        <div class="field-span"><small>Assigned Office Head reviewer</small><b>{{ $application->officeHeadReviewer->name }}{{ $application->status === 'Under Alternate Office Head Review' ? ' · Authorized alternate review' : '' }}</b></div>
                    @endif
                    @if($application->office_head_routing_reason)
                        <div class="field-span"><small>Alternate routing</small><b>{{ auth()->user()->canManageHr() || auth()->user()->isAdministrator() || $application->office_head_reviewer_id === auth()->id() ? $application->office_head_routing_reason : 'The assigned Office Head was unavailable; the application was routed to an authorized alternate.' }}</b></div>
                    @endif
                    @if($sensitiveDetailsVisible)
                        @if($application->leave_detail_category)<div><small>Specific details</small><b>{{ $application->leave_detail_category }}</b></div>@endif
                        @if($application->destination)<div><small>Destination</small><b>{{ $application->destination }}</b></div>@endif
                        @if($application->leave_detail)<div class="field-span"><small>Leave details</small><b>{{ $application->leave_detail }}</b></div>@endif
                        @if($application->additional_details)<div class="field-span"><small>Additional information</small><b>{{ $application->additional_details }}</b></div>@endif
                    @elseif($application->leaveType->is_sensitive)
                        <div class="field-span"><small>Restricted details</small><b>Detailed information and supporting records are available only to the employee and authorized HR personnel.</b></div>
                    @endif
                </div>
            </section>
            <section class="panel panel-spaced">
                <div class="panel-heading"><div><h2>Supporting documents</h2><p>Files are private and available only to authorized users.</p></div></div>
                @forelse($application->documents as $document)
                    @if((!$document->is_sensitive && !$application->leaveType->is_sensitive) || auth()->user()->canManageHr() || auth()->user()->isAdministrator() || auth()->user()->employee?->id === $application->employee_id)
                        <a class="document-row" href="{{ route('leave.documents', [$application, $document]) }}"><span class="document-icon">↧</span><span><b>{{ $document->document_type ?: $document->original_name }}</b><small>{{ number_format($document->size_bytes / 1024, 0) }} KB · {{ $document->created_at->format('M j, Y') }}</small></span><span class="text-link">Download</span></a>
                    @endif
                @empty<p class="empty-note">No supporting documents attached.</p>@endforelse
            </section>
            @if($application->credit_certification && !auth()->user()->isOfficeHead())
                <section class="panel panel-spaced">
                    <div class="panel-heading"><div><h2>7.A Certification of working days applied for</h2><p>As of {{ $application->credit_certification['as_of'] ?? '—' }} · Certified by {{ $application->certified_by ? \App\Models\User::find($application->certified_by)?->name : '—' }}{{ $application->certifier_position ? ' · '.$application->certifier_position : '' }}</p>@if($application->certifier_signature)<p>Digital signature: {{ $application->certifier_signature }}</p>@endif</div></div>
                    <div class="table-wrap"><table><thead><tr><th>DESCRIPTION</th><th>VACATION LEAVE</th><th>SICK LEAVE</th></tr></thead><tbody>
                        @foreach(['total_earned' => 'Total earned', 'used_before_application' => 'Less: used before this application', 'less_this_application' => 'Less: this application', 'balance' => 'Balance'] as $key => $label)
                            <tr><td>{{ $label }}</td><td>{{ isset($application->credit_certification['vacation'][$key]) ? number_format($application->credit_certification['vacation'][$key], 2) : '—' }}</td><td>{{ isset($application->credit_certification['sick'][$key]) ? number_format($application->credit_certification['sick'][$key], 2) : '—' }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    @if($application->hr_remarks)<p class="review-remarks"><b>HRMO remarks:</b> {{ $application->hr_remarks }}</p>@endif
                </section>
            @endif
            @if($application->recommendation)
                <section class="panel panel-spaced"><div class="panel-heading"><div><h2>7.B Recommendation</h2><p>Recorded by {{ $application->recommended_by ? \App\Models\User::find($application->recommended_by)?->name : 'Office Head' }}</p></div></div><p><b>{{ $application->recommendation }}</b></p><p class="review-remarks">{{ $application->recommendation_remarks ?: 'No remarks.' }}</p></section>
            @endif
            @if(in_array($application->status, ['Approved','Disapproved'], true))
                <section class="panel panel-spaced"><div class="panel-heading"><div><h2>{{ $application->status === 'Approved' ? '7.C Approved for' : '7.D Disapproved due to' }}</h2><p>Decision recorded {{ $application->decided_at?->format('F j, Y · g:i A') }} by {{ $application->decided_by ? \App\Models\User::find($application->decided_by)?->name : 'Authorized approver' }}</p></div></div>
                    @if($application->status === 'Approved')<div class="detail-grid"><div><small>Days with pay</small><b>{{ number_format((float)$application->approved_days_with_pay, 2) }}</b></div><div><small>Days without pay</small><b>{{ number_format((float)$application->approved_days_without_pay, 2) }}</b></div><div><small>Others</small><b>{{ $application->approval_others ?? '—' }}</b></div></div>@else<p><b>Reason:</b> {{ $application->disapproval_reason }}</p>@endif
                    @if($application->decision_remarks)<p class="review-remarks">{{ $application->decision_remarks }}</p>@endif
                </section>
            @endif
        </div>
        <aside class="application-aside">
            @can('review', $application)
                @if(auth()->user()->isOfficeHead())
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>Office Head review</h2><p>Record recommendation and remarks.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf
                            <label>{{ $application->status === 'Under Alternate Office Head Review' ? 'Alternate recommendation' : 'Recommendation' }}<select name="recommendation" required><option value="For approval">For approval</option><option value="For disapproval">For disapproval</option></select></label>
                            <label>Remarks<textarea name="remarks" rows="3" placeholder="Required for disapproval or return"></textarea></label>
                            <button class="button button-primary button-wide" name="action" value="endorse">Send for HRMO review</button>
                            <button class="button button-outline button-wide" name="action" value="return">Return for correction</button>
                        </form>
                    </section>
                @elseif(auth()->user()->isAdministrator() && $application->status === 'Awaiting Alternate HR Reviewer Assignment')
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>Assign alternate HR reviewer</h2><p>The Admin applicant cannot verify their own leave request.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf
                            <input type="hidden" name="action" value="assign_hr_reviewer">
                            <label>Authorized alternate Admin reviewer *
                                <select name="assigned_hr_reviewer_id" required><option value="">Select an active Admin</option>@foreach(\App\Models\User::query()->where('role', 'admin')->where('is_active', true)->whereKeyNot($application->employee->user_id)->orderBy('name')->get() as $reviewer)<option value="{{ $reviewer->id }}">{{ $reviewer->name }}</option>@endforeach</select>
                            </label>
                            <label>Assignment remarks<textarea name="remarks" rows="2"></textarea></label>
                            <button class="button button-primary button-wide">Assign and forward for HR verification</button>
                        </form>
                    </section>
                @elseif(auth()->user()->isAdministrator() && $application->status === 'Awaiting Authorized Final Approver Assignment')
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>Assign authorized final approver</h2><p>No authority is assumed for the Mayor’s personal leave.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf
                            <input type="hidden" name="action" value="assign_final_approver">
                            <label>Explicitly designated Admin approver *
                                <select name="assigned_final_approver_id" required><option value="">Select an active designated approver</option>@foreach(\App\Models\User::query()->where('role', 'admin')->where('is_active', true)->where('can_final_approve', true)->orderBy('name')->get() as $approver)<option value="{{ $approver->id }}">{{ $approver->name }}</option>@endforeach</select>
                            </label>
                            <label>Assignment remarks<textarea name="remarks" rows="2"></textarea></label>
                            <button class="button button-primary button-wide">Assign authorized approver</button>
                        </form>
                    </section>
                @elseif(auth()->user()->isHrmo())
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>HRMO verification</h2><p>Verify credits and working days.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf<input type="hidden" name="action" value="verify">
                            <label>Calculated days<input value="{{ $application->working_days }}" readonly></label>
                            <label>Verified working days *<input type="number" min="1" name="verified_working_days" value="{{ $application->working_days }}" required></label>
                            <label>Certifying position *<input name="certifier_position" maxlength="120" required></label>
                            <label>Digital signature (type your full name) *<input name="certifier_signature" maxlength="120" value="{{ auth()->user()->name }}" required></label>
                            <label>Verification remarks<textarea name="remarks" rows="3"></textarea></label>
                            @if($requiredDocuments)
                                <div class="waiver-box"><b>Required supporting documents</b><small>Every checklist item must have an attached file or a documented HRMO waiver before verification. Waivers require an approved policy basis and justification.</small>
                                    @foreach($requiredDocuments as $document)
                                        @php($matchingDocument = $application->documents->firstWhere('document_type', $document))
                                        <div class="document-requirement-row"><span><b>{{ $document }}</b><small>{{ $matchingDocument ? 'Attached: '.$matchingDocument->original_name : 'Not attached' }}</small></span>@if($matchingDocument)<a class="table-link" href="{{ route('leave.documents', [$application, $matchingDocument]) }}">Open attachment</a>@else<label class="checkbox-label"><input type="checkbox" name="waived_documents[]" value="{{ $document }}" @checked(in_array($document, $application->document_waivers['documents'] ?? [], true))> HRMO waiver</label>@endif</div>
                                    @endforeach
                                    <label>Waiver justification<textarea name="waiver_justification" rows="2">{{ $application->document_waivers['justification'] ?? '' }}</textarea></label>
                                </div>
                            @endif
                            @if($application->leaveType->rules['eligibility_review_required'] ?? false)
                                <div class="waiver-box">
                                    <b>Individual eligibility confirmation</b>
                                    <small>Confirm that the employee meets the applicable eligibility requirements for this leave category. This decision is recorded with your HRMO account.</small>
                                    @if($application->eligibility_verified_at)
                                        <p>Previously confirmed by {{ $application->eligibilityVerifier?->name ?? 'authorized HRMO' }} on {{ $application->eligibility_verified_at->format('M j, Y') }}.</p>
                                    @endif
                                    <label class="checkbox-label"><input type="checkbox" name="eligibility_verified" value="1" required> I reviewed and confirm the employee’s eligibility</label>
                                    <label>Eligibility review remarks<textarea name="eligibility_remarks" rows="2">{{ $application->eligibility_remarks }}</textarea></label>
                                </div>
                            @endif
                            <button class="button button-primary button-wide" type="submit">Certify and send for approval</button>
                        </form>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack review-divider">@csrf
                            <input type="hidden" name="action" value="return">
                            <label>Return to employee for correction *<textarea name="remarks" rows="2" required></textarea></label>
                            <button class="button button-outline button-wide" type="submit">Return for correction</button>
                        </form>
                    </section>
                @elseif(auth()->user()->isMayor() && $application->status === 'Under Alternate Office Head Review')
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>Alternate Office Head review</h2><p>Endorsement is separate from the Mayor’s later final decision. HR Office verification remains mandatory.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf
                            <input type="hidden" name="recommendation" value="For approval">
                            <label>Remarks<textarea name="remarks" rows="3"></textarea></label>
                            <button class="button button-primary button-wide" name="action" value="endorse">Endorse to HR Office</button>
                            <button class="button button-outline button-wide" name="action" value="return">Return for correction</button>
                        </form>
                    </section>
                @elseif(auth()->user()->canFinalApprove() || (auth()->user()->canApproveMayorLeave() && $application->status === 'Pending Authorized Final Approval'))
                    <section class="panel review-panel"><div class="panel-heading"><div><h2>Final decision</h2><p>Record the authorized final action.</p></div></div>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack">@csrf
                            <label>Number of days with pay<input type="number" min="0" step=".5" name="days_with_pay" value="{{ $application->verified_working_days ?? $application->working_days }}" required></label>
                            <label>Number of days without pay<input type="number" min="0" step=".5" name="days_without_pay" value="0"></label>
                            <label>Others (specify)<input name="approval_others"></label>
                            <label>Remarks<textarea name="remarks" rows="2"></textarea></label>
                            <button class="button button-primary button-wide" name="action" value="approve">Approve application</button>
                        </form>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack review-divider">@csrf
                            <input type="hidden" name="action" value="disapprove"><label>Reason for disapproval *<textarea name="reason" rows="2" required></textarea></label>
                            <label>Additional remarks<textarea name="remarks" rows="2"></textarea></label>
                            <button class="button button-danger button-wide" type="submit">Disapprove application</button>
                        </form>
                        <form method="POST" action="{{ route('leave.review', $application) }}" class="form-stack review-divider">@csrf
                            <input type="hidden" name="action" value="return"><label>Return for clarification *<textarea name="remarks" rows="2" required></textarea></label>
                            <button class="button button-outline button-wide" type="submit">Return for clarification</button>
                        </form>
                    </section>
                @endif
            @endcan
            <section class="panel timeline-panel"><div class="panel-heading"><div><h2>Approval history</h2><p>Complete record of actions</p></div></div>
                @forelse($application->history as $history)
                        <div class="timeline-item"><span class="timeline-dot"></span><b>{{ str_replace('_', ' ', ucfirst($history->action)) }}</b><small>{{ $history->user?->name ?? 'System' }} · {{ str_replace('_', ' ', $history->role) }}</small><small>{{ $history->created_at->format('M j, Y · g:i A') }}</small>@if($history->remarks && $sensitiveDetailsVisible)<p>{{ $history->remarks }}</p>@endif</div>
                @empty<p class="empty-note">No history recorded.</p>@endforelse
            </section>
        </aside>
    </div>
@endsection
