<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application for Leave · {{ $application->id }}</title>
    @vite(['resources/js/app.js', 'resources/js/leave-print.js'])
</head>
<body class="print-body">
    <div class="print-toolbar">
        <a href="{{ route('leave.show', $application) }}">← Back to application</a>
        <div class="print-actions">
            <span id="pdf-download-status" role="status" aria-live="polite"></span>
            <button type="button" class="button button-outline" id="download-leave-pdf" data-application-id="{{ $application->id }}">Download PDF</button>
            <button type="button" class="button button-primary" onclick="window.print()">Print</button>
        </div>
    </div>
    <main class="paper">
        <section class="paper-page page-one">
            <header class="paper-header"><div class="paper-official">REPUBLIC OF THE PHILIPPINES<br><b>PROVINCE OF CAGAYAN</b><br><b>MUNICIPALITY OF CAMALANIUGAN</b><br><strong>HUMAN RESOURCE MANAGEMENT OFFICE</strong></div><div class="paper-form-no">Civil Service Form No. 6<br>Revised 2020<br>Application #{{ $application->id }}</div></header>
            <h1 class="paper-title">APPLICATION FOR LEAVE</h1>
            <div class="paper-box"><div class="paper-section">1. OFFICE / DEPARTMENT</div><div class="paper-value">{{ $application->office_snapshot }}</div></div>
            <div class="paper-grid paper-box">
                <div><div class="paper-section">2. NAME</div><div class="paper-value">{{ $application->last_name_snapshot }}, {{ $application->first_name_snapshot }} {{ $application->middle_name_snapshot }}</div></div>
                <div><div class="paper-section">3. DATE OF FILING</div><div class="paper-value">{{ $application->filed_at?->format('F j, Y') ?? '—' }}</div></div>
                <div><div class="paper-section">4. POSITION</div><div class="paper-value">{{ $application->position_snapshot ?? '—' }}</div></div>
                <div><div class="paper-section">5. SALARY</div><div class="paper-value">{{ !auth()->user()->isOfficeHead() && $application->salary_snapshot ? '₱'.number_format((float)$application->salary_snapshot, 2) : '—' }}</div></div>
            </div>
            <div class="paper-section-band">6. DETAILS OF APPLICATION</div>
            <div class="paper-grid-two">
                <div class="paper-box"><div class="paper-section">6.A TYPE OF LEAVE TO BE AVAILED OF</div>
                    @foreach($application->leaveType ? \App\Models\LeaveType::where('is_active', true)->orderBy('sort_order')->get() : [] as $type)
                        <div class="paper-check"><span>{{ $application->leave_type_id === $type->id ? '☒' : '☐' }}</span>{{ $type->name }} @if($type->code === 'OTHER' && $application->other_leave_type)<u>{{ $application->other_leave_type }}</u>@endif</div>
                    @endforeach
                </div>
                <div class="paper-box"><div class="paper-section">6.B DETAILS OF LEAVE</div>
                    <div class="paper-subhead">In case of Vacation / Special Privilege Leave:</div><div class="paper-check"><span>{{ $application->leave_detail_category === 'Within the Philippines' ? '☒' : '☐' }}</span>Within the Philippines</div><div class="paper-check"><span>{{ $application->leave_detail_category === 'Abroad' ? '☒' : '☐' }}</span>Abroad — {{ $application->destination ?: 'Specify destination' }}</div>
                    <div class="paper-subhead">In case of Sick Leave:</div><div class="paper-value">{{ !$sensitiveDetailsVisible && $application->leaveType->is_sensitive ? 'Restricted details — refer to HRMO' : ((in_array($application->leave_detail_category, ['In Hospital','Out Patient'], true) ? $application->leave_detail_category.' · ' : '').($application->leave_type_id === \App\Models\LeaveType::where('code','SICK')->value('id') ? ($application->leave_detail ?? 'Specify illness') : '')) }}</div>
                    <div class="paper-subhead">Other purpose / details:</div><div class="paper-value paper-fill">{{ $sensitiveDetailsVisible ? ($application->leave_detail ?: $application->additional_details ?: '—') : 'Restricted details — refer to HRMO' }}</div>
                </div>
            </div>
            <div class="paper-grid">
                <div class="paper-box"><div class="paper-section">6.C NUMBER OF WORKING DAYS APPLIED FOR</div><div class="paper-value">{{ $application->verified_working_days ?? $application->working_days ?? '—' }} working days</div><div class="paper-small">Inclusive dates: {{ $application->start_date?->format('F j, Y') ?? '—' }} to {{ $application->end_date?->format('F j, Y') ?? '—' }}</div></div>
                <div class="paper-box"><div class="paper-section">6.D COMMUTATION</div><div class="paper-check"><span>{{ $application->commutation_requested === false ? '☒' : '☐' }}</span>Not Requested</div><div class="paper-check"><span>{{ $application->commutation_requested === true ? '☒' : '☐' }}</span>Requested</div><div class="paper-signature">Applicant acknowledgment: {{ $application->applicant_acknowledgment ?: '__________________________' }}</div></div>
            </div>
            <div class="paper-section-band">7. DETAILS OF ACTION ON APPLICATION</div>
            <div class="paper-box"><div class="paper-section">7.A CERTIFICATION OF WORKING DAYS APPLIED FOR</div><div class="paper-small">As of: {{ $application->credit_certification['as_of'] ?? '________________' }}</div>
                <table class="paper-table"><thead><tr><th>DESCRIPTION</th><th>VACATION LEAVE</th><th>SICK LEAVE</th></tr></thead><tbody>
                    @foreach(['total_earned' => 'Total earned', 'used_before_application' => 'Less: used to date', 'less_this_application' => 'Less this application', 'balance' => 'Balance'] as $key => $label)
                    <tr><td>{{ $label }}</td><td>{{ $sensitiveDetailsVisible && isset($application->credit_certification['vacation'][$key]) ? number_format($application->credit_certification['vacation'][$key], 2) : '' }}</td><td>{{ $sensitiveDetailsVisible && isset($application->credit_certification['sick'][$key]) ? number_format($application->credit_certification['sick'][$key], 2) : '' }}</td></tr>
                    @endforeach
                </tbody></table>
                <div class="paper-signature">Certified correct: {{ $sensitiveDetailsVisible && $application->certified_by ? \App\Models\User::find($application->certified_by)?->name : '' }}<br>{{ $sensitiveDetailsVisible ? ($application->certifier_position ?? '') : '' }}<br>{{ $sensitiveDetailsVisible ? ($application->certifier_signature ?? '') : '' }}<br><small>HRMO Representative / Digital signature</small></div>
            </div>
            <div class="paper-grid">
                <div class="paper-box"><div class="paper-section">7.B RECOMMENDATION</div><div class="paper-check"><span>{{ $application->recommendation === 'For approval' ? '☒' : '☐' }}</span>For approval</div><div class="paper-check"><span>{{ $application->recommendation === 'For disapproval' ? '☒' : '☐' }}</span>For disapproval due to</div><div class="paper-value paper-fill">{{ $application->recommendation_remarks ?: '' }}</div><div class="paper-signature">{{ $application->recommended_by ? \App\Models\User::find($application->recommended_by)?->name : '' }} · Office Head</div></div>
                <div class="paper-box"><div class="paper-section">7.C APPROVED FOR</div><div class="paper-value">Days with pay: {{ $application->approved_days_with_pay ?? '' }}</div><div class="paper-value">Days without pay: {{ $application->approved_days_without_pay ?? '' }}</div><div class="paper-value">Others: {{ $application->approval_others ?? '' }}</div><div class="paper-subhead">7.D DISAPPROVED DUE TO</div><div class="paper-value paper-fill">{{ $application->disapproval_reason ?? '' }}</div><div class="paper-signature">{{ $application->decided_by ? \App\Models\User::find($application->decided_by)?->name : '' }} · Approver {{ $application->decided_at?->format('M j, Y') }}</div></div>
            </div>
            <footer class="paper-footer">Status: {{ $application->status }} · Application #{{ $application->id }} · Generated {{ now()->format('M j, Y g:i A') }}</footer>
        </section>
        <section class="paper-page page-two">
            <header class="paper-header"><div class="paper-official"><b>MUNICIPALITY OF CAMALANIUGAN</b><br>HUMAN RESOURCE MANAGEMENT OFFICE</div><div class="paper-form-no">APPLICATION FOR LEAVE<br>Instructions and requirements</div></header>
            <h2 class="paper-title">INSTRUCTIONS AND REQUIREMENTS</h2>
            <p class="paper-disclaimer">This guide is configurable and provided for application support. The 2020 form and these examples do not establish current legal entitlements. HR must confirm current Civil Service Commission rules and approved municipal policies before action.</p>
            <ol class="instruction-list">
                @foreach(\App\Models\LeaveType::where('is_active', true)->orderBy('sort_order')->get() as $type)
                    <li><b>{{ $type->name }}</b><p>{{ $type->guidance }}</p>@if($type->required_documents)<ul>@foreach($type->required_documents as $document)<li>{{ $document }}</li>@endforeach</ul>@endif</li>
                @endforeach
            </ol>
            <div class="paper-note"><b>Privacy notice.</b> Medical and VAWC-related documents are restricted. Access is limited to the applicant and authorized HR personnel; approvers receive only information required for their action.</div>
            <footer class="paper-footer">Questions about current requirements? Contact the Human Resource Management Office before filing.</footer>
        </section>
    </main>
</body>
</html>
