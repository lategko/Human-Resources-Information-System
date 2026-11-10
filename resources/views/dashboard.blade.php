@extends('layouts.app')
@section('title', $pageTitle)
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">MUNICIPAL HRIS</span><h1>{{ $pageTitle }}</h1><p>{{ now()->format('l, F j, Y') }} <span class="muted-separator">·</span> Welcome, {{ auth()->user()->name }}</p></div>
        @if($role === 'employee')<a class="button button-primary" href="{{ route('leave.create') }}">＋ Apply for leave</a>@endif
    </div>
    @if($role === 'employee')
        <div class="welcome-strip"><div><span class="eyebrow">YOUR EMPLOYEE PROFILE</span><h2>{{ $employee->full_name }}</h2><p>{{ $employee->position?->title ?? 'Position not set' }} <span>·</span> {{ $employee->department->name }}</p></div><a href="{{ route('employees.show', $employee) }}" class="text-link">View profile <span>→</span></a></div>
        <div class="stats-grid stats-grid-3">
            <article class="stat-card"><span class="stat-label">LEAVE CREDITS</span><strong>{{ number_format($credits->sum(fn ($credit) => $credit->balance), 1) }}</strong><small>days available across tracked types</small><span class="stat-icon">◷</span></article>
            <article class="stat-card"><span class="stat-label">PRESENT THIS MONTH</span><strong>{{ $presentCount }}</strong><small>attendance records marked present</small><span class="stat-icon">✓</span></article>
            <article class="stat-card"><span class="stat-label">LATE ARRIVALS</span><strong>{{ $lateCount }}</strong><small>this month</small><span class="stat-icon">↗</span></article>
        </div>
        <div class="content-grid">
            <section class="panel"><div class="panel-heading"><div><h2>Leave credits</h2><p>Current balances from your HR records</p></div><span class="panel-icon">◷</span></div>
                @forelse($credits as $credit)
                    <div class="credit-row"><span><b>{{ $credit->leaveType->name }}</b><small>Earned {{ number_format((float)$credit->earned_credits, 1) }} days</small></span><strong>{{ number_format($credit->balance, 1) }} <small>days</small></strong></div>
                @empty<p class="empty-note">No leave credit balances are recorded yet.</p>@endforelse
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Recent applications</h2><p>Track the progress of your requests</p></div><a href="{{ route('leave.index') }}" class="text-link">View all →</a></div>
                @forelse($applications as $application)
                    <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->leaveType->name }}</b><small>{{ $application->start_date?->format('M j') }}{{ $application->end_date?->ne($application->start_date) ? ' – '.$application->end_date?->format('M j, Y') : '' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></a>
                @empty<p class="empty-note">You have no leave applications yet.</p>@endforelse
            </section>
        </div>
    @else
        <div class="stats-grid">
            <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon">♧</span></article>
            <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>present or late attendance records</small><span class="stat-icon">✓</span></article>
            <article class="stat-card"><span class="stat-label">ABSENT TODAY</span><strong>{{ number_format($absentCount) }}</strong><small>from attendance records</small><span class="stat-icon">◷</span></article>
            <article class="stat-card"><span class="stat-label">PENDING LEAVE</span><strong>{{ number_format($pendingCount) }}</strong><small>awaiting action in the workflow</small><span class="stat-icon">▤</span></article>
        </div>
        <div class="content-grid">
            <section class="panel panel-wide"><div class="panel-heading"><div><h2>Leave applications</h2><p>Recent requests within your authorized scope</p></div><a href="{{ route('leave.index') }}" class="text-link">Open applications →</a></div>
                @forelse($recentApplications as $application)
                    <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->leaveType->name }} · {{ $application->employee->department->name }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></a>
                @empty<p class="empty-note">There are no leave applications in this view.</p>@endforelse
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Attendance trend</h2><p>Recorded attendance · last 14 days</p></div><span class="panel-icon">◷</span></div><div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Attendance trend chart"></canvas></div>
                <script>document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));</script>
            </section>
            <section class="panel"><div class="panel-heading"><div><h2>Attendance today</h2><p>{{ now()->format('F j, Y') }}</p></div><span class="panel-icon">◷</span></div>
                @forelse($recentAttendance as $record)
                    <div class="attendance-row"><span class="avatar avatar-small">{{ strtoupper(substr($record->employee->first_name, 0, 1)) }}</span><span class="row-main"><b>{{ $record->employee->full_name }}</b><small>{{ $record->employee->department->name }}</small></span><span class="attendance-status">{{ $record->status }}</span></div>
                @empty<p class="empty-note">No attendance records have been entered today.</p>@endforelse
                <div class="dashboard-footnote">Dashboard counts use current database records. Missing attendance is not inferred as an absence.</div>
            </section>
        </div>
    @endif
@endsection
