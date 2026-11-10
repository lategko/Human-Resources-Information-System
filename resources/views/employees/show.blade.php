@extends('layouts.app')
@section('title', 'Employee profile')
@section('content')
    <div class="page-heading"><div><span class="eyebrow">EMPLOYEE PROFILE · {{ $employee->employee_no }}</span><h1>{{ $employee->full_name }}</h1><p>{{ $employee->position?->title ?? 'Position not set' }} <span class="muted-separator">·</span> {{ $employee->department->name }}</p></div>
        <div class="heading-actions">@can('update', $employee)<a class="button button-outline" href="{{ route('employees.edit', $employee) }}">Edit record</a>@endcan
            @can('updatePersonal', $employee)<a class="button button-primary" href="{{ route('my-profile.edit') }}">Update contact info</a>@endcan
            @if(auth()->user()->can('viewAny', App\Models\Employee::class))<a class="button button-outline" href="{{ route('employees.index') }}">Back to directory</a>@endif</div>
    </div>
    <div class="profile-layout"><section class="panel profile-card"><div class="profile-avatar">{{ strtoupper(substr($employee->first_name, 0, 1).substr($employee->last_name, 0, 1)) }}</div><h2>{{ $employee->full_name }}</h2><span class="status-tag status-{{ \Illuminate\Support\Str::slug($employee->employment_status) }}">{{ $employee->employment_status }}</span><div class="profile-meta"><span>Department</span><b>{{ $employee->department->name }}</b><span>Employee ID</span><b>{{ $employee->employee_no }}</b><span>Position</span><b>{{ $employee->position?->title ?? '—' }}</b></div></section>
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
        <section class="panel panel-spaced"><div class="panel-heading"><div><h2>Leave credit balances</h2><p>Balances and transaction history should be verified by HRMO.</p></div></div>
        @forelse($employee->leaveCredits as $credit)<div class="credit-row"><span><b>{{ $credit->leaveType->name }}</b><small>Effective {{ $credit->effective_date?->format('M j, Y') ?? '—' }}</small></span><strong>{{ number_format($credit->balance, 2) }} <small>days</small></strong></div>@empty<p class="empty-note">No credits recorded.</p>@endforelse</section>
    @endif
    @can('archive', $employee)
        @if(!$employee->trashed())<form class="archive-form" method="POST" action="{{ route('employees.archive', $employee) }}" onsubmit="return confirm('Archive this employee record? It will be retained and can no longer be selected as active personnel.')">@csrf<button class="button button-danger" type="submit">Archive employee</button></form>@endif
    @endcan
@endsection
