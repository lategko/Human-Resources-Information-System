@extends('layouts.app')
@section('title', $pageTitle)
@section('content')
    <div class="page-heading">
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
                <p>{{ $employee->position?->title ?? 'Position not set' }} <span>·</span> {{ $employee->department->name }}</p>
            </div>
            <a href="{{ route('employees.show', $employee) }}" class="text-link">View profile <i data-lucide="arrow-right"></i></a>
        </div>
        <div class="quick-links" aria-label="Employee shortcuts">
            <a class="button button-primary" href="{{ route('leave.create') }}"><i data-lucide="file-plus-2"></i> Apply for leave</a>
            <a class="button button-outline" href="#leave-credits"><i data-lucide="wallet"></i> View leave credits</a>
            <a class="button button-outline" href="{{ route('attendance.index') }}"><i data-lucide="clock-3"></i> View attendance</a>
            <a class="button button-outline" href="{{ route('my-profile.edit') }}"><i data-lucide="user-round"></i> Update my profile</a>
            <a class="button button-outline" href="{{ route('leave.index') }}"><i data-lucide="file-clock"></i> Track applications</a>
        </div>
        <div class="stats-grid stats-grid-3">
            <article class="stat-card"><span class="stat-label">LEAVE CREDITS</span><strong>{{ number_format($credits->sum(fn ($credit) => $credit->balance), 1) }}</strong><small>days available across tracked types</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
            <article class="stat-card"><span class="stat-label">PRESENT THIS MONTH</span><strong>{{ $presentCount }}</strong><small>recorded as present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
            <article class="stat-card"><span class="stat-label">LATE ARRIVALS</span><strong>{{ $lateCount }}</strong><small>recorded this month</small><span class="stat-icon"><i data-lucide="clock-3"></i></span></article>
        </div>
        <div class="content-grid">
            <section id="leave-credits" class="panel">
                <div class="panel-heading"><div><h2>Leave credits</h2><p>Current balances from your HR records</p></div><i class="panel-icon" data-lucide="wallet"></i></div>
                @forelse($credits as $credit)
                    <div class="credit-row"><span><b>{{ $credit->leaveType->name }}</b><small>Earned {{ number_format((float) $credit->earned_credits, 1) }} days</small></span><strong>{{ number_format($credit->balance, 1) }} <small>days</small></strong></div>
                @empty<p class="empty-note">No leave credit balances are recorded yet.</p>@endforelse
            </section>
            <section class="panel">
                <div class="panel-heading"><div><h2>Recent applications</h2><p>Track the progress of your requests</p></div><a href="{{ route('leave.index') }}" class="text-link">View all <i data-lucide="arrow-right"></i></a></div>
                @forelse($applications as $application)
                    <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->leaveType->name }}</b><small>{{ $application->start_date?->format('M j') }}{{ $application->end_date?->ne($application->start_date) ? ' – '.$application->end_date?->format('M j, Y') : '' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($application->status) }}">{{ $application->status }}</span></a>
                @empty<p class="empty-note">You have no leave applications yet.</p>@endforelse
            </section>
            <section class="panel">
                <div class="panel-heading"><div><h2>Recent attendance</h2><p>Latest recorded entries</p></div><a href="{{ route('attendance.index') }}" class="text-link">View attendance <i data-lucide="arrow-right"></i></a></div>
                @forelse($recentAttendance as $record)
                    <div class="attendance-row"><span class="row-date">{{ $record->work_date->format('M d') }}</span><span class="row-main"><b>{{ $record->work_date->format('l') }}</b><small>{{ $record->time_in ? substr($record->time_in, 0, 5) : 'No time-in recorded' }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }} {{ $record->corrected_by ? 'status-corrected' : '' }}">{{ $record->corrected_by ? 'Corrected · ' : '' }}{{ $record->status }}</span></div>
                @empty<p class="empty-note">No attendance records are available yet.</p>@endforelse
            </section>
            <section class="panel">
                <div class="panel-heading"><div><h2>Upcoming approved leave</h2><p>Approved leave scheduled within the next 30 days</p></div><i class="panel-icon" data-lucide="calendar-days"></i></div>
                @forelse($upcomingLeaves as $leave)
                    <div class="application-row"><span class="row-date">{{ $leave->start_date?->format('M d') }}</span><span class="row-main"><b>{{ $leave->leaveType->name }}</b><small>{{ $leave->end_date?->format('M j, Y') }}</small></span><span class="status-tag status-approved">Approved</span></div>
                @empty<p class="empty-note">No upcoming approved leave is recorded.</p>@endforelse
            </section>
        </div>
    @else
        <form class="dashboard-filters panel" method="GET" aria-label="Dashboard filters">
            <label>Trend from<input type="date" name="date_from" value="{{ $dateFrom }}"></label>
            <label>Trend to<input type="date" name="date_to" value="{{ $dateTo }}"></label>
            @if($departments->isNotEmpty())
                <label>Department<select name="department_id"><option value="">All departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string) $selectedDepartmentId === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></label>
            @endif
            <button type="submit" class="button button-outline"><i data-lucide="list-filter"></i> Apply filters</button>
        </form>

        @if($role === 'office_head')
            <div class="stats-grid">
                <article class="stat-card"><span class="stat-label">ACTIVE DEPARTMENT EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>employees within your assigned department</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
                <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>recorded present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
                <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
                <article class="stat-card"><span class="stat-label">PENDING RECOMMENDATIONS</span><strong>{{ number_format($pendingCount) }}</strong><small>awaiting your department review</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
            </div>
            <div class="content-grid">
                <section class="panel panel-wide">
                    <div class="panel-heading"><div><h2>Leave applications for your review</h2><p>Only applications from your assigned department are shown.</p></div><a href="{{ route('leave.index') }}" class="text-link">Open applications <i data-lucide="arrow-right"></i></a></div>
                    @forelse($pendingTasks as $application)
                        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department->name }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-submitted">Needs recommendation</span></a>
                    @empty<p class="empty-note">There are no leave applications awaiting your recommendation.</p>@endforelse
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Department attendance</h2><p>Recorded attendance · selected period</p></div><i class="panel-icon" data-lucide="chart-no-axes-combined"></i></div>
                    <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Department attendance trend"></canvas></div>
                    <div class="dashboard-footnote">Only recorded attendance is counted. Missing records are not treated as absences.</div>
                    <script>document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));</script>
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Employees currently on leave</h2><p>Approved leave covering today</p></div></div>
                    <div class="stat-inline"><strong>{{ number_format($leaveTodayCount) }}</strong><span>employees with approved leave today</span></div>
                    <a class="button button-outline button-wide top-space" href="{{ route('leave.index', ['status' => 'Approved']) }}">View approved applications</a>
                </section>
            </div>
        @elseif($role === 'hrmo')
            <div class="stats-grid stats-grid-3">
                <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
                <article class="stat-card"><span class="stat-label">HRMO VERIFICATION</span><strong>{{ number_format($pendingCount) }}</strong><small>leave applications awaiting HR review</small><span class="stat-icon"><i data-lucide="clipboard-check"></i></span></article>
                <article class="stat-card"><span class="stat-label">ACTIVE LEAVE CREDIT RECORDS</span><strong>{{ number_format($activeCreditCount) }}</strong><small>records available for monitoring</small><span class="stat-icon"><i data-lucide="wallet"></i></span></article>
                <article class="stat-card"><span class="stat-label">LATE TODAY</span><strong>{{ number_format($lateCount) }}</strong><small>recorded late arrivals</small><span class="stat-icon"><i data-lucide="clock-3"></i></span></article>
                <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
                <article class="stat-card"><span class="stat-label">ON APPROVED LEAVE</span><strong>{{ number_format($leaveTodayCount) }}</strong><small>approved leave covering today</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
            </div>
            <div class="content-grid">
                <section class="panel panel-wide">
                    <div class="panel-heading"><div><h2>Pending HR actions</h2><p>Leave applications requiring HRMO verification and certification</p></div><a href="{{ route('leave.index', ['status' => 'Under HRMO Verification']) }}" class="text-link">View all <i data-lucide="arrow-right"></i></a></div>
                    @forelse($pendingTasks as $application)
                        <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department->name }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-under-hrmo-verification">HR verification</span></a>
                    @empty<p class="empty-note">There are no leave applications awaiting HRMO verification.</p>@endforelse
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Attendance monitoring</h2><p>Today’s recorded attendance</p></div><a href="{{ route('attendance.index') }}" class="text-link">Open attendance <i data-lucide="arrow-right"></i></a></div>
                    @forelse($recentAttendance as $record)
                        <div class="attendance-row"><span class="avatar avatar-small">{{ strtoupper(substr($record->employee->first_name, 0, 1)) }}</span><span class="row-main"><b>{{ $record->employee->full_name }}</b><small>{{ $record->employee->department->name }}</small></span><span class="status-tag status-{{ \Illuminate\Support\Str::slug($record->status) }} {{ $record->corrected_by ? 'status-corrected' : '' }}">{{ $record->corrected_by ? 'Corrected · ' : '' }}{{ $record->status }}</span></div>
                    @empty<p class="empty-note">No attendance records have been entered today.</p>@endforelse
                    <div class="dashboard-footnote">No attendance record is distinct from a recorded absence.</div>
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Upcoming approved leaves</h2><p>Next 30 days</p></div><i class="panel-icon" data-lucide="calendar-days"></i></div>
                    @forelse($upcomingLeaves as $leave)
                        <a class="application-row" href="{{ route('leave.show', $leave) }}"><span class="row-date">{{ $leave->start_date?->format('M d') }}</span><span class="row-main"><b>{{ $leave->employee->full_name }}</b><small>{{ $leave->leaveType->name }}</small></span><span class="status-tag status-approved">Approved</span></a>
                    @empty<p class="empty-note">No upcoming approved leave is recorded.</p>@endforelse
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Attendance trend</h2><p>Recorded attendance · selected period</p></div></div>
                    <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Attendance trend chart"></canvas></div>
                    <script>document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));</script>
                </section>
            </div>
        @else
            <div class="stats-grid stats-grid-3">
                <article class="stat-card"><span class="stat-label">ACTIVE EMPLOYEES</span><strong>{{ number_format($employeeCount) }}</strong><small>{{ $departmentCount }} departments in scope</small><span class="stat-icon"><i data-lucide="users"></i></span></article>
                <article class="stat-card"><span class="stat-label">PRESENT TODAY</span><strong>{{ number_format($presentCount) }}</strong><small>recorded present or late</small><span class="stat-icon"><i data-lucide="circle-check"></i></span></article>
                <article class="stat-card"><span class="stat-label">RECORDED ABSENCES</span><strong>{{ number_format($absentCount) }}</strong><small>absence records for today</small><span class="stat-icon"><i data-lucide="circle-alert"></i></span></article>
                @if(auth()->user()->canFinalApprove())
                    <article class="stat-card"><span class="stat-label">PENDING FINAL DECISIONS</span><strong>{{ number_format($pendingCount) }}</strong><small>leave applications awaiting your decision</small><span class="stat-icon"><i data-lucide="file-clock"></i></span></article>
                @endif
                <article class="stat-card"><span class="stat-label">ON APPROVED LEAVE</span><strong>{{ number_format($leaveTodayCount) }}</strong><small>approved leave covering today</small><span class="stat-icon"><i data-lucide="calendar-days"></i></span></article>
            </div>
            <div class="content-grid">
                <section class="panel panel-wide">
                    <div class="panel-heading"><div><h2>Pending executive decisions</h2><p>Final approvals assigned to your account</p></div><a href="{{ route('leave.index', ['status' => 'Pending Final Approval']) }}" class="text-link">Review applications <i data-lucide="arrow-right"></i></a></div>
                    @if(auth()->user()->canFinalApprove())
                        @forelse($pendingTasks as $application)
                            <a class="application-row" href="{{ route('leave.show', $application) }}"><span class="row-date">{{ $application->created_at->format('M d') }}</span><span class="row-main"><b>{{ $application->employee->full_name }}</b><small>{{ $application->employee->department->name }} · {{ $application->leaveType->name }}</small></span><span class="status-tag status-pending-final-approval">Final approval</span></a>
                        @empty<p class="empty-note">There are no leave applications awaiting your final decision.</p>@endforelse
                    @else
                        <p class="empty-note">Final approval actions are available only to the designated approver.</p>
                    @endif
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Department staffing</h2><p>Active employees by department</p></div><a href="{{ route('reports.index') }}" class="text-link">Reports <i data-lucide="arrow-right"></i></a></div>
                    @forelse($departmentDistribution as $department)
                        <div class="distribution-row"><span><b>{{ $department->name }}</b><small>{{ $department->employees_count }} active {{ \Illuminate\Support\Str::plural('employee', $department->employees_count) }}</small></span><span class="distribution-bar" aria-hidden="true"><i style="width: {{ $employeeCount > 0 ? min(100, round($department->employees_count / $employeeCount * 100)) : 0 }}%"></i></span></div>
                    @empty<p class="empty-note">No active departments are recorded.</p>@endforelse
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Attendance trend</h2><p>Recorded attendance · selected period</p></div><i class="panel-icon" data-lucide="chart-no-axes-combined"></i></div>
                    <div class="attendance-summary">
                        <div><small>PRESENT OR LATE · MONTH TO DATE</small><b>{{ number_format($monthlyPresentCount) }}</b></div>
                        <div><small>LATE RECORDS</small><b>{{ number_format($monthlyLateCount) }}</b></div>
                        <div><small>RECORDED ABSENCES</small><b>{{ number_format($monthlyAbsentCount) }}</b></div>
                    </div>
                    <div class="chart-wrap"><canvas id="attendanceTrend" aria-label="Municipal attendance trend"></canvas></div>
                    <script>document.addEventListener('DOMContentLoaded', () => window.drawAttendanceChart(@json($chartData)));</script>
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Leave application trend</h2><p>Applications filed · selected period</p></div><i class="panel-icon" data-lucide="chart-no-axes-column-increasing"></i></div>
                    <div class="chart-wrap"><canvas id="leaveApplicationsTrend" aria-label="Leave applications trend"></canvas></div>
                    <script>document.addEventListener('DOMContentLoaded', () => window.drawLeaveChart(@json($leaveChartData)));</script>
                </section>
                <section class="panel">
                    <div class="panel-heading"><div><h2>Recent HR activity</h2><p>Administrative events recorded by the system</p></div><a href="{{ route('audit.index') }}" class="text-link">Audit logs <i data-lucide="arrow-right"></i></a></div>
                    @forelse($recentActivities as $activity)
                        <div class="activity-row"><span class="activity-icon"><i data-lucide="activity"></i></span><span class="row-main"><b>{{ \Illuminate\Support\Str::headline($activity->action) }}</b><small>{{ $activity->user?->name ?? 'System' }} · {{ $activity->created_at->diffForHumans() }}</small></span></div>
                    @empty<p class="empty-note">No administrative activity has been recorded.</p>@endforelse
                </section>
            </div>
            <div class="dashboard-footnote">Dashboard summaries use current database records. Missing attendance is not inferred as an absence. Personal and supporting-document details are not included in this overview.</div>
        @endif
    @endif
@endsection
