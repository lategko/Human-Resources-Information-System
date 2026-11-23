@extends('layouts.app')
@section('title', 'Employee profile')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">EMPLOYEE PROFILE · {{ $employee->employee_no }}</span><h1>{{ $employee->full_name }}</h1><p>{{ $employee->position?->title ?? 'Position not set' }} <span class="muted-separator">·</span> {{ $employee->department?->name ?? 'Department not assigned' }}</p></div>
        <div class="heading-actions">@can('update', $employee)<a class="button button-outline" href="{{ route('employees.edit', $employee) }}">Edit record</a>@endcan
            @can('updatePersonal', $employee)<a class="button button-primary" href="{{ route('account.profile.edit') }}">Edit my profile</a>@endcan
            @if(auth()->user()->can('viewAny', App\Models\Employee::class))<a class="button button-outline" href="{{ route('employees.index') }}">Back to directory</a>@endif</div>
    </div>
    <div class="profile-layout employee-profile-layout"><section class="panel profile-card employee-profile-card"><div class="profile-avatar">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</div><h2>{{ $employee->full_name }}</h2><span class="status-tag status-{{ \Illuminate\Support\Str::slug($employee->employment_status) }}">{{ $employee->employment_status }}</span><div class="profile-meta"><span>Department</span><b>{{ $employee->department?->name ?? 'Department not assigned' }}</b><span>Employee ID</span><b>{{ $employee->employee_no }}</b><span>Position</span><b>{{ $employee->position?->title ?? '—' }}</b></div></section>
        <section class="panel profile-details"><div class="panel-heading"><div><h2>Personnel information</h2><p>Confidential details shown according to your role.</p></div></div><div class="detail-grid">
            <div><small>Employment type</small><b>{{ $employee->employment_type ?? '—' }}</b></div><div><small>Date hired</small><b>{{ $employee->date_hired?->format('F j, Y') ?? '—' }}</b></div>
            @if(!auth()->user()->isOfficeHead() || auth()->user()->canManageHr() || auth()->user()->isAdministrator() || auth()->user()->employee?->is($employee))
                <div><small>Email address</small><b>{{ $employee->email ?? '—' }}</b></div><div><small>Contact number</small><b>{{ $employee->contact_number ?? '—' }}</b></div><div><small>Date of birth</small><b>{{ $employee->date_of_birth?->format('F j, Y') ?? '—' }}</b></div><div><small>Civil status</small><b>{{ $employee->civil_status ?? '—' }}</b></div>
            @endif
            @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator() || auth()->user()->employee?->is($employee))
                <div><small>Salary grade / step</small><b>{{ $employee->salary_grade ?? '—' }} / {{ $employee->salary_step ?? '—' }}</b></div><div><small>Monthly basic salary</small><b>{{ $employee->monthly_salary ? '₱'.number_format((float)$employee->monthly_salary, 2) : '—' }}</b></div>
            @endif
            @if(!auth()->user()->isOfficeHead() || auth()->user()->employee?->is($employee))
                <div class="field-span"><small>Residential address</small><b>{{ $employee->address ?? '—' }}</b></div>
                <div><small>Emergency contact</small><b>{{ $employee->emergency_contact_name ?? '—' }}{{ $employee->emergency_contact_number ? ' · '.$employee->emergency_contact_number : '' }}</b></div>
            @endif
        </div></section>
    </div>
    @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())
        <section class="panel panel-spaced">
            <div class="panel-heading"><div><h2>Employment history</h2><p>Appointment history retained from verified source records.</p></div></div>
            @forelse($employee->employmentHistories as $history)
                <div class="application-row"><span class="row-date">{{ $history->appointment_date?->format('M j, Y') ?? 'Date not recorded' }}</span><span class="row-main"><b>{{ $history->position_title }}</b><small>History entry {{ $history->sequence }}</small></span></div>
            @empty<p class="empty-note">No employment history has been recorded.</p>@endforelse
        </section>
        <section class="panel panel-spaced">
            <div class="panel-heading"><div><h2>Department and position assignments</h2><p>Assignment changes recorded from this release forward; imported appointment history remains separate.</p></div></div>
            @forelse($employee->assignmentHistories as $history)
                <div class="application-row">
                    <span class="row-date">{{ $history->effective_date->format('M j, Y') }}</span>
                    <span class="row-main">
                        <b>{{ $history->change_type === 'initial' ? 'Initial assignment' : 'Assignment change' }}</b>
                        <small>
                            {{ $history->previous_position_title ?? 'No previous position' }} · {{ $history->previous_department_name ?? 'No previous department' }}
                            to {{ $history->position_title ?? 'No position' }} · {{ $history->department_name ?? 'No department' }}
                            · Recorded by {{ $history->changedBy?->name ?? 'System' }}
                            @if($history->reason) · {{ $history->reason }}@endif
                        </small>
                    </span>
                </div>
            @empty<p class="empty-note">No department or position assignment history has been recorded.</p>@endforelse
        </section>
        @if($employee->serviceProfile)
            <section class="panel panel-spaced">
                <div class="panel-heading"><div><h2>Qualifications and service information</h2><p>Restricted to authorized HRMO and administrators.</p></div></div>
                <div class="detail-grid">
                    <div><small>Position level</small><b>{{ $employee->serviceProfile->position_level ?? '—' }}</b></div>
                    <div><small>Source classification</small><b>{{ $employee->serviceProfile->source_classification ?? '—' }}</b></div>
                    <div><small>Eligibility / rating</small><b>{{ $employee->serviceProfile->eligibility_rating ?? '—' }}</b></div>
                    <div><small>Professional license (source)</small><b>{{ $employee->serviceProfile->professional_license ?? '—' }}</b></div>
                    <div><small>License expiration</small><b>{{ $employee->serviceProfile->license_expiration_date?->format('M j, Y') ?? '—' }}</b></div>
                    <div><small>Educational attainment</small><b>{{ $employee->serviceProfile->educational_attainment ?? '—' }}</b></div>
                    <div><small>Years in service</small><b>{{ $employee->serviceProfile->years_in_service ?? '—' }}</b></div>
                    <div><small>Additional service years (source)</small><b>{{ $employee->serviceProfile->additional_service_years ?? '—' }}</b></div>
                    <div><small>Expected mandatory retirement</small><b>{{ $employee->serviceProfile->expected_retirement_date?->format('M j, Y') ?? '—' }}</b></div>
                    <div><small>Age at source</small><b>{{ $employee->serviceProfile->age_at_source ?? '—' }}</b></div>
                </div>
            </section>
        @endif
    @endif
    @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())
        <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Leave credit balances</h2><p>Balances and transaction history should be verified by HRMO.</p></div></div>
        @forelse($employee->leaveCredits as $credit)<div class="credit-row"><span><b>{{ $credit->leaveType->name }}</b><small>Effective {{ $credit->effective_date?->format('M j, Y') ?? '—' }}</small></span><strong>{{ number_format($credit->balance, 2) }} <small>days</small></strong></div>@empty<p class="empty-note">No credits recorded.</p>@endforelse</section>
    @endif
    @can('archive', $employee)
        @if(!$employee->trashed())<form class="archive-form" method="POST" action="{{ route('employees.archive', $employee) }}" onsubmit="return confirm('Archive this employee record? It will be retained and can no longer be selected as active personnel.')">@csrf<button class="button button-danger" type="submit">Archive employee</button></form>@endif
    @endcan
@endsection
