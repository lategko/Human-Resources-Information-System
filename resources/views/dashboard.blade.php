@extends('layouts.app')
@section('title', $pageTitle)
@section('content')
<div class="page-heading dashboard-heading">
    <div>
        <span class="eyebrow">MUNICIPAL HRIS</span>
        <h1>{{ $pageTitle }}</h1>
        <p>{{ now()->format('l, F j, Y') }} <span class="muted-separator">·</span> Welcome, {{ auth()->user()->name }}</p>
    </div>
    @if($role === 'employee')
    <a class="button button-primary" href="{{ route('leave.create') }}"><i data-lucide="file-plus-2"></i> Apply for leave</a>
    @endif
</div>

@if($role === 'employee')
<div class="welcome-strip">
    <div>
        <span class="eyebrow">YOUR EMPLOYEE PROFILE</span>
        <h2>{{ $employee->full_name }}</h2>
        <p>{{ $employee->position?->title ?? 'Position not set' }} <span>·</span> {{ $employee->department?->name ?? 'Department not assigned' }}</p>
    </div>
    <a href="{{ route('employees.show', $employee) }}" class="text-link">View profile <i data-lucide="arrow-right"></i></a>
</div>
<div class="quick-links" aria-label="Employee shortcuts">
    <a class="button button-primary" href="{{ route('leave.create') }}"><i data-lucide="file-plus-2"></i> Apply for leave</a>
    <a class="button button-outline" href="{{ route('leave-credits.index') }}"><i data-lucide="wallet"></i> View leave credits</a>
    <a class="button button-outline" href="{{ route('attendance.index') }}"><i data-lucide="clock-3"></i> View attendance</a>
    <a class="button button-outline" href="{{ route('my-profile.edit') }}"><i data-lucide="user-round"></i> Update my profile</a>
    <a class="button button-outline" href="{{ route('leave.index') }}"><i data-lucide="file-clock"></i> Track applications</a>
</div>
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">VACATION LEAVE</span><strong>{{ number_format($credits->firstWhere('leaveType.code', 'VACATION')?->balance ?? 0, 2) }}</strong><small>{{ number_format((float) ($credits->firstWhere('leaveType.code', 'VACATION')?->leaveType->rules['monthly_accrual'] ?? 0), 2) }} monthly accrual, if eligible</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
    <article class="stat-card"><span class="stat-label">SICK LEAVE</span><strong>{{ number_format($credits->firstWhere('leaveType.code', 'SICK')?->balance ?? 0, 2) }}</strong><small>{{ number_format((float) ($credits->firstWhere('leaveType.code', 'SICK')?->leaveType->rules['monthly_accrual'] ?? 0), 2) }} monthly accrual, if eligible</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
    <article class="stat-card"><span class="stat-label">PENDING APPLICATIONS</span><strong>{{ $applicationStatusCounts->get('Under Office Head Review', 0) + $applicationStatusCounts->get('Under Alternate Office Head Review', 0) + $applicationStatusCounts->get('Under HRMO Verification', 0) + $applicationStatusCounts->get('Pending Final Approval', 0) }}</strong><small>currently awaiting review</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
</div>
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">APPROVED</span><strong>{{ $applicationStatusCounts->get('Approved', 0) }}</strong><small>approved leave applications</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">DISAPPROVED</span><strong>{{ $applicationStatusCounts->get('Disapproved', 0) }}</strong><small>completed decisions</small><span class="stat-icon"><i data-lucide="circle-x"></i></span></article>
    <article class="stat-card"><span class="stat-label">UPCOMING APPROVED LEAVE</span><strong>{{ $upcomingLeaves->count() }}</strong><small>scheduled within the next 30 days</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
</div>
<div class="content-grid dashboard-content-grid">
    <section id="leave-credits" class="panel">
        <div class="panel-heading">
            <div>
                <h2>Leave credits</h2>
                <p>Current balances from your HR records</p>
            </div><i class="panel-icon" data-lucide="wallet"></i>
        </div>
        @forelse($credits as $credit)
        <div class="credit-row"><span><b>{{ $credit->leaveType->name }}</b><small>Earned {{ number_format((float) $credit->earned_credits, 1) }} days</small></span><strong>{{ number_format($credit->balance, 1) }} <small>days</small></strong></div>
        @empty<p class="empty-note">No leave credit balances are recorded yet.</p>@endforelse
    </section>
    @if($specialEntitlements->isNotEmpty())
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Special leave entitlements</h2>
                <p>Separate from VL and SL balances</p>
            </div><i class="panel-icon" data-lucide="calendar-days"></i>
        </div>
        @foreach($specialEntitlements as $summary)
        <div class="credit-row"><span><b>{{ $summary['type']->name }}</b><small>{{ $summary['application_count'] }} pending or approved application(s) in {{ $summary['year'] }}</small></span><strong>{{ number_format($summary['available'], 1) }} <small>of {{ number_format($summary['entitlement'], 1) }}</small></strong></div>
        @endforeach
    </section>
    @endif
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent applications</h2>
                <p>Track the progress of your requests</p>
            </div><a href="{{ route('leave.index') }}" class="text-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($applications as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->leaveType->name }}</b><small>{{ $application->start_date?->format('M j') }}{{ $application->end_date?->ne($application->start_date) ? ' – '.$application->end_date?->format('M j, Y') : '' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></a>
        @empty<p class="empty-note">You have no leave applications yet.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent attendance</h2>
                <p>Latest recorded entries</p>
            </div><a href="{{ route('attendance.index') }}" class="text-link">View attendance <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentAttendance as $record)
        <div class="attendance-row"><span class="row-date">{{ $record->work_date->format('M d') }}</span><span class="row-main"><b>{{ $record->work_date->format('l') }}</b><small>{{ $record->time_in ? substr($record->time_in, 0, 5) : 'No time-in recorded' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }} {{ $record->corrected_by ? 'status-corrected' : '' }}">{{ $record->corrected_by ? 'Corrected · ' : '' }}{{ $record->display_status }}</span></div>
        @empty<p class="empty-note">No attendance records are available yet.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Upcoming approved leave</h2>
                <p>Approved leave scheduled within the next 30 days</p>
            </div><i class="panel-icon" data-lucide="calendar-days"></i>
        </div>
        @forelse($upcomingLeaves as $leave)
        <div class="application-row"><span class="row-date">{{ $leave->start_date?->format('M d') }}</span><span class="row-main"><b>{{ $leave->leaveType->name }}</b><small>{{ $leave->end_date?->format('M j, Y') }}</small></span><span class="status-tag status-approved">Approved</span></div>
        @empty<p class="empty-note">No upcoming approved leave is recorded.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Notifications</h2>
                <p>Recent updates about your HRIS activity</p>
            </div><a href="{{ route('notifications.index') }}" class="text-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentNotifications as $notification)
        <a class="application-row" href="{{ isset($notification->data['application_id']) ? route('leave.show', $notification->data['application_id']) : route('notifications.index') }}"><span class="row-main"><b>{{ $notification->data['message'] ?? 'HRIS notification' }}</b><small>{{ $notification->created_at->diffForHumans() }}</small></span><span class="status-tag {{ $notification->read_at ? '' : 'status-submitted' }}">{{ $notification->read_at ? 'Read' : 'New' }}</span></a>
        @empty<p class="empty-note">No notifications are available.</p>@endforelse
    </section>
</div>
@else
@if($role === 'administrator')
<div class="role-intro"><span class="role-intro-icon"><i data-lucide="briefcase-business" aria-hidden="true"></i></span>
    <div>
        <h2>Human resources operations</h2>
        <p>Personnel records, leave verification, attendance monitoring, and municipal HR activity.</p>
    </div>
</div>
@elseif($role === 'office_head')
<div class="role-intro"><span class="role-intro-icon"><i data-lucide="building-2" aria-hidden="true"></i></span>
    <div>
        <h2>Your department workspace</h2>
        <p>Operational summaries and leave tasks are limited to your assigned department.</p>
    </div>
</div>
@elseif($role === 'mayor')
<div class="role-intro"><span class="role-intro-icon"><i data-lucide="landmark" aria-hidden="true"></i></span>
    <div>
        <h2>Municipal executive overview</h2>
        <p>Workforce, attendance, and approval decisions across the municipality.</p>
    </div>
</div>
@endif

<form class="dashboard-filters panel" method="GET" aria-label="Dashboard filters">
    <label>Trend from<input type="date" name="date_from" value="{{ $dateFrom }}"></label>
    <label>Trend to<input type="date" name="date_to" value="{{ $dateTo }}"></label>
    @if($departments->isNotEmpty())
    <label>Department<select name="department_id">
            <option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) $selectedDepartmentId===(string) $department->id)>{{ $department->name }}</option>@endforeach
        </select></label>
    @endif
    <button type="submit" class="button button-outline"><i data-lucide="list-filter"></i> Apply filters</button>
</form>

@if($role === 'office_head')
<div class="stats-grid">
    <article class="stat-card"><span class="stat-label">ACTIVE DEPARTMENT EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>employees within your assigned department</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
    <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>recorded present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
    <article class="stat-card"><span class="stat-label">PENDING RECOMMENDATIONS</span><strong>{{ number_format($pendingCount) }}</strong><small>awaiting your department review</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
    <article class="stat-card"><span class="stat-label">APPROVED LEAVE REQUESTS</span><strong>{{ number_format($approvedLeaveCount) }}</strong><small>within your assigned office</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">RETURNED FOR CORRECTION</span><strong>{{ number_format($returnedLeaveCount) }}</strong><small>department applications</small><span class="stat-icon"><i data-lucide="corner-up-left"></i></span></article>
</div>
<div class="content-grid dashboard-content-grid">
    <section class="panel panel-wide">
        <div class="panel-heading">
            <div>
                <h2>Leave applications for your review</h2>
                <p>Only applications from your assigned department are shown.</p>
            </div><a href="{{ route('leave.index') }}" class="text-link">Open applications <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingTasks as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department?->name ?? 'Department not assigned' }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-submitted">Needs recommendation</span></a>
        @empty<p class="empty-note">There are no leave applications awaiting your recommendation.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Department attendance</h2>
                <p>Recorded attendance · selected period</p>
            </div><i class="panel-icon" data-lucide="chart-no-axes-combined"></i>
        </div>
        <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Department attendance trend"></canvas></div>
        <div class="dashboard-footnote">Only recorded attendance is counted. Missing records are not treated as absences.</div>
        <script>
            document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));
        </script>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Employees currently on leave</h2>
                <p>Approved leave covering today</p>
            </div>
        </div>
        <div class="stat-inline"><strong>{{ number_format($leaveTodayCount) }}</strong><span>employees with approved leave today</span></div>
        <a class="button button-outline button-wide top-space" href="{{ route('leave.index', ['status' => 'Approved']) }}">View approved applications</a>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent leave requests</h2>
                <p>Applications from your assigned department</p>
            </div><a href="{{ route('leave.index') }}" class="text-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentApplications as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->leaveType->name }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></a>
        @empty<p class="empty-note">No leave requests have been recorded for your department.</p>@endforelse
    </section>
    <section id="approval-history" class="panel">
        <div class="panel-heading">
            <div>
                <h2>Your approval history</h2>
                <p>Actions you recorded for your department</p>
            </div>
        </div>
        @forelse($approvalHistory as $history)
        <a class="application-row" href="{{ route('leave.show', $history->application) }}"><span class="row-date">{{ $history->created_at->format('M d') }}</span><span class="row-main"><b>{{ $history->application->employee->full_name }}</b><small>{{ \Illuminate\Support\Str::headline($history->action) }} · {{ $history->application->leaveType->name }}</small></span><span class="status-tag">{{ $history->to_status ?? $history->from_status }}</span></a>
        @empty<p class="empty-note">No approval actions have been recorded yet.</p>@endforelse
    </section>
</div>
@elseif($role === 'administrator')
@if($leaveCreditStats)
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">EMPLOYEES WITH VL</span><strong>{{ number_format($leaveCreditStats['vl_employees']) }}</strong><small>employees with a positive Vacation Leave balance</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
    <article class="stat-card"><span class="stat-label">EMPLOYEES WITH SL</span><strong>{{ number_format($leaveCreditStats['sl_employees']) }}</strong><small>employees with a positive Sick Leave balance</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
    <article class="stat-card"><span class="stat-label">PENDING LEAVE APPLICATIONS</span><strong>{{ number_format($leaveCreditStats['pending']) }}</strong><small>all current review stages</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
    <article class="stat-card"><span class="stat-label">APPROVED APPLICATIONS</span><strong>{{ number_format($leaveCreditStats['approved']) }}</strong><small>recorded approvals</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">DISAPPROVED APPLICATIONS</span><strong>{{ number_format($leaveCreditStats['disapproved']) }}</strong><small>recorded disapprovals</small><span class="stat-icon"><i data-lucide="circle-x"></i></span></article>
    <article class="stat-card"><span class="stat-label">LOW OR ZERO VL / SL</span><strong>{{ number_format($leaveCreditStats['low_balance_employees']) }}</strong><small>regular leave accounts at or below 3 days</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
    <article class="stat-card"><span class="stat-label">MONTHLY ACCRUAL</span><strong>{{ number_format($leaveCreditStats['monthly_accrual'], 2) }}</strong><small>VL and SL credits accrued in {{ $leaveCreditStats['accrual_month'] }}</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
    <article class="stat-card"><span class="stat-label">SPECIAL LEAVE APPLICATIONS</span><strong>{{ number_format($leaveCreditStats['special_applications']) }}</strong><small>special/statutory categories requiring HR review</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
</div>
<div class="quick-links" aria-label="Leave management shortcuts">
    <a class="button button-outline" href="{{ route('leave-credits.index') }}">Review leave credits and ledgers</a>
    <a class="button button-outline" href="{{ route('reports.index') }}">Open leave reports</a>
</div>
@endif
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
    <article class="stat-card"><span class="stat-label">INACTIVE EMPLOYEES</span><strong>{{ number_format($inactiveEmployeeCount) }}</strong><small>non-archived records not marked active</small><span class="stat-icon"><i data-lucide="user-round-x"></i></span></article>
    <article class="stat-card"><span class="stat-label">HRMO VERIFICATION</span><strong>{{ number_format($pendingCount) }}</strong><small>leave applications awaiting HR review</small><span class="stat-icon"><i data-lucide="clipboard-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">ACTIVE LEAVE CREDIT RECORDS</span><strong>{{ number_format($activeCreditCount) }}</strong><small>records available for monitoring</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
    <article class="stat-card"><span class="stat-label">LATE TODAY</span><strong>{{ number_format($lateCount) }}</strong><small>recorded late arrivals</small><span class="stat-icon"><i data-lucide="clock-3"></i></span></article>
    <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
    <article class="stat-card"><span class="stat-label">ON APPROVED LEAVE</span><strong>{{ number_format($leaveTodayCount) }}</strong><small>approved leave covering today</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
    @if(auth()->user()->canApproveMayorLeave())
    <article class="stat-card"><span class="stat-label">MAYOR LEAVE DECISIONS</span><strong>{{ number_format($pendingAuthorizedFinalTasks->count()) }}</strong><small>assigned for your separate authorized decision</small><span class="stat-icon"><i data-lucide="stamp"></i></span></article>
    @endif
</div>
<div class="content-grid dashboard-content-grid">
    <section class="panel panel-wide">
        <div class="panel-heading">
            <div>
                <h2>Pending HR actions</h2>
                <p>Leave applications requiring HRMO verification and certification</p>
            </div><a href="{{ route('leave.index', ['status' => 'Under HRMO Verification']) }}" class="text-link">View all <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingTasks as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department?->name ?? 'Department not assigned' }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-under-hrmo-verification">HR verification</span></a>
        @empty<p class="empty-note">There are no leave applications awaiting HRMO verification.</p>@endforelse
    </section>
    @if(auth()->user()->canApproveMayorLeave())
    <section class="panel panel-wide">
        <div class="panel-heading">
            <div>
                <h2>Mayor personal-leave decisions</h2>
                <p>Only applications explicitly assigned to your account are listed.</p>
            </div><a href="{{ route('leave.index', ['status' => 'Pending Authorized Final Approval']) }}" class="text-link">Open decisions <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingAuthorizedFinalTasks as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department?->name ?? 'Department not assigned' }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-submitted">Authorized decision</span></a>
        @empty<p class="empty-note">There are no Mayor personal-leave applications assigned to you.</p>@endforelse
    </section>
    @endif
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Employee workbook imports</h2>
                <p>Confidential batches awaiting mapping, import, or correction</p>
            </div><a href="{{ route('employee-imports.index') }}" class="text-link">Import history <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingEmployeeImports as $import)
        <a class="application-row" href="{{ route('employee-imports.show', $import) }}">
            <span class="row-date">#{{ $import->id }}</span>
            <span class="row-main"><b>{{ $import->original_name }}</b><small>{{ $import->row_count }} source rows · {{ $import->imported_count }} imported · {{ $import->review_count }} requiring correction</small></span>
            <span class="status-tag">{{ match ($import->status) {
                                'mapping_required', 'pending_review' => 'Mapping Required',
                                'ready_for_import', 'mapping_approved' => 'Ready for Import',
                                'importing' => 'Importing',
                                'completed' => 'Completed',
                                'completed_with_errors', 'review_required' => 'Completed with Errors',
                                'failed' => 'Failed',
                                default => ucfirst(str_replace('_', ' ', $import->status)),
                            } }}</span>
        </a>
        @empty<p class="empty-note">No employee import batches need attention.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Attendance monitoring</h2>
                <p>Today’s recorded attendance</p>
            </div><a href="{{ route('attendance.index') }}" class="text-link">Open attendance <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentAttendance as $record)
        <div class="attendance-row"><span class="avatar avatar-small">{{ strtoupper(substr($record->employee->first_name, 0, 1)) }}</span><span class="row-main"><b>{{ $record->employee->full_name }}</b><small>{{ $record->employee->department?->name ?? 'Department not assigned' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }} {{ $record->corrected_by ? 'status-corrected' : '' }}">{{ $record->corrected_by ? 'Corrected · ' : '' }}{{ $record->display_status }}</span></div>
        @empty<p class="empty-note">No attendance records have been entered today.</p>@endforelse
        <div class="dashboard-footnote">No attendance record is distinct from a recorded absence.</div>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Upcoming approved leaves</h2>
                <p>Next 30 days</p>
            </div><i class="panel-icon" data-lucide="calendar-days"></i>
        </div>
        @forelse($upcomingLeaves as $leave)
        <a class="application-row" href="{{ route('leave.show', $leave) }}"><span class="row-date">{{ $leave->start_date?->format('M d') }}</span><span class="row-main"><b>{{ $leave->employee->full_name }}</b><small>{{ $leave->leaveType->name }}</small></span><span class="status-tag status-approved">Approved</span></a>
        @empty<p class="empty-note">No upcoming approved leave is recorded.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Attendance trend</h2>
                <p>Recorded attendance · selected period</p>
            </div>
        </div>
        <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Attendance trend chart"></canvas></div>
        <script>
            document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));
        </script>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Department staffing</h2>
                <p>Active employees by office</p>
            </div><a href="{{ route('departments.index') }}" class="text-link">Departments <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($departmentDistribution as $department)
        <div class="distribution-row"><span><b>{{ $department->name }}</b><small>{{ $department->employees_count }} active {{ \Illuminate\Support\Str::plural('employee', $department->employees_count) }}</small></span><span class="distribution-bar" aria-hidden="true"><i style="width: {{ $employeeCount > 0 ? min(100, round($department->employees_count / $employeeCount * 100)) : 0 }}%"></i></span></div>
        @empty<p class="empty-note">No active departments are recorded.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent HR activities</h2>
                <p>System audit events for operational review</p>
            </div><a href="{{ route('audit.index') }}" class="text-link">Audit logs <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentActivities as $activity)
        <div class="activity-row"><span class="activity-icon"><i data-lucide="activity"></i></span><span class="row-main"><b>{{ \Illuminate\Support\Str::headline($activity->action) }}</b><small>{{ $activity->user?->name ?? 'System' }} · {{ $activity->created_at->diffForHumans() }}</small></span></div>
        @empty<p class="empty-note">No HR activity has been recorded.</p>@endforelse
    </section>
</div>
@elseif($role === 'mayor')
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
    <article class="stat-card"><span class="stat-label">INACTIVE EMPLOYEES</span><strong>{{ number_format($inactiveEmployeeCount) }}</strong><small>non-archived records not marked active</small><span class="stat-icon"><i data-lucide="user-round-x"></i></span></article>
    <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>recorded present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
    <article class="stat-card"><span class="stat-label">PENDING FINAL DECISIONS</span><strong>{{ number_format($pendingFinalTasks->count()) }}</strong><small>leave applications awaiting your decision</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
    <article class="stat-card"><span class="stat-label">ESCALATED OFFICE REVIEWS</span><strong>{{ number_format($pendingAlternateTasks->count()) }}</strong><small>routed to you as alternate approver</small><span class="stat-icon"><i data-lucide="user-round-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">ON APPROVED LEAVE</span><strong>{{ number_format($leaveTodayCount) }}</strong><small>approved leave covering today</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
</div>
<div class="content-grid dashboard-content-grid">
    <section class="panel panel-wide">
        <div class="panel-heading">
            <div>
                <h2>Pending executive decisions</h2>
                <p>Final approvals assigned to your account</p>
            </div><a href="{{ route('leave.index', ['status' => 'Pending Final Approval']) }}" class="text-link">Review applications <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingFinalTasks as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department?->name ?? 'Department not assigned' }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-pending-final-approval">Final approval</span></a>
        @empty<p class="empty-note">There are no leave applications awaiting your final decision.</p>@endforelse
    </section>
    <section class="panel panel-wide">
        <div class="panel-heading">
            <div>
                <h2>Office Head unavailability escalations</h2>
                <p>You are the assigned alternate approver; HRMO verification follows your decision.</p>
            </div><a href="{{ route('leave.index', ['status' => 'Under Alternate Office Head Review']) }}" class="text-link">View escalations <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($pendingAlternateTasks as $application)
        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department?->name ?? 'Department not assigned' }} · {{ $application->leaveType->name }} · {{ $application->office_head_routing_reason }}</small></span><span class="status-tag">Alternate review</span></a>
        @empty<p class="empty-note">No leave requests are currently escalated to your account.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Department staffing</h2>
                <p>Active employees by department</p>
            </div><a href="{{ route('reports.index') }}" class="text-link">Reports <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($departmentDistribution as $department)
        <div class="distribution-row"><span><b>{{ $department->name }}</b><small>{{ $department->employees_count }} active {{ \Illuminate\Support\Str::plural('employee', $department->employees_count) }}</small></span><span class="distribution-bar" aria-hidden="true"><i style="width: {{ $employeeCount > 0 ? min(100, round($department->employees_count / $employeeCount * 100)) : 0 }}%"></i></span></div>
        @empty<p class="empty-note">No active departments are recorded.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Attendance trend</h2>
                <p>Recorded attendance · selected period</p>
            </div><i class="panel-icon" data-lucide="chart-no-axes-combined"></i>
        </div>
        <div class="attendance-summary">
            <div><small>PRESENT OR LATE · MONTH TO DATE</small><b>{{ number_format($monthlyPresentCount) }}</b></div>
            <div><small>LATE RECORDS</small><b>{{ number_format($monthlyLateCount) }}</b></div>
            <div><small>RECORDED ABSENCES</small><b>{{ number_format($monthlyAbsentCount) }}</b></div>
        </div>
        <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Municipal attendance trend"></canvas></div>
        <script>
            document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));
        </script>
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Leave application trend</h2>
                <p>Applications filed · selected period</p>
            </div><i class="panel-icon" data-lucide="chart-no-axes-column-increasing"></i>
        </div>
        <div class="chart-wrap"><canvas id="leaveApplicationsTrend" aria-label="Leave applications trend"></canvas></div>
        <script>
            document.addEventListener('DOMContentLoaded', () => window.drawLeaveChart(@json($leaveChartData)));
        </script>
    </section>
    <section id="approval-history" class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent approval activity</h2>
                <p>Recorded decisions and workflow transitions</p>
            </div><a href="{{ route('leave.index') }}" class="text-link">Approval history <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($approvalHistory as $history)
        <a class="application-row" href="{{ route('leave.show', $history->application) }}"><span class="row-date">{{ $history->created_at->format('M d') }}</span><span class="row-main"><b>{{ $history->application->employee->full_name }}</b><small>{{ \Illuminate\Support\Str::headline($history->action) }} · {{ $history->application->leaveType->name }}</small></span><span class="status-tag">{{ $history->to_status ?? $history->from_status }}</span></a>
        @empty<p class="empty-note">No approval history has been recorded.</p>@endforelse
    </section>
</div>
<div class="dashboard-footnote">Dashboard summaries use current database records. Missing attendance is not inferred as an absence. Personal and supporting-document details are not included in this overview.</div>
@else
<div class="stats-grid stats-grid-3">
    <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
    <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>recorded present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
    <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>recorded absences only</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
</div>
<div class="content-grid dashboard-content-grid">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Department staffing</h2>
                <p>Active employees by department</p>
            </div>
        </div>
        @forelse($departmentDistribution as $department)
        <div class="distribution-row"><span><b>{{ $department->name }}</b><small>{{ $department->employees_count }} active employees</small></span><span class="distribution-bar" aria-hidden="true"><i style="width: {{ $employeeCount > 0 ? min(100, round($department->employees_count / $employeeCount * 100)) : 0 }}%"></i></span></div>
        @empty<p class="empty-note">No active departments are recorded.</p>@endforelse
    </section>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Recent administrative activity</h2>
                <p>System audit events</p>
            </div><a href="{{ route('audit.index') }}" class="text-link">Audit logs <i data-lucide="arrow-right"></i></a>
        </div>
        @forelse($recentActivities as $activity)
        <div class="activity-row"><span class="activity-icon"><i data-lucide="activity"></i></span><span class="row-main"><b>{{ \Illuminate\Support\Str::headline($activity->action) }}</b><small>{{ $activity->user?->name ?? 'System' }} · {{ $activity->created_at->diffForHumans() }}</small></span></div>
        @empty<p class="empty-note">No administrative activity has been recorded.</p>@endforelse
    </section>
</div>
@endif
@endif
@endsection