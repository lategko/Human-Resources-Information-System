@extends('layouts.app')
@section('title', 'Super Admin overview')
@section('content')
    <div class="page-heading dashboard-heading">
        <div><span class="eyebrow">SYSTEM GOVERNANCE</span><h1>Super Admin overview</h1><p>System-wide account access and administrative activity.</p></div>
        <a class="button button-primary" href="{{ route('super-admin.users.index') }}"><i data-lucide="users-round" aria-hidden="true"></i> Manage accounts</a>
    </div>

    <div class="stats-grid">
        <article class="stat-card"><span class="stat-label">REGISTERED USERS</span><strong>{{ number_format($totalUsers) }}</strong><small>all account roles</small><span class="stat-icon"><i data-lucide="users-round"></i></span></article>
        <article class="stat-card"><span class="stat-label">ADMINISTRATORS</span><strong>{{ number_format($totalAdmins) }}</strong><small>regular ADMIN accounts</small><span class="stat-icon"><i data-lucide="shield-check"></i></span></article>
        <article class="stat-card"><span class="stat-label">HRMO ACCOUNTS</span><strong>{{ number_format($totalHrmo) }}</strong><small>human resource operations</small><span class="stat-icon"><i data-lucide="briefcase-business"></i></span></article>
        <article class="stat-card"><span class="stat-label">OFFICE HEADS</span><strong>{{ number_format($totalOfficeHeads) }}</strong><small>department account holders</small><span class="stat-icon"><i data-lucide="building-2"></i></span></article>
    </div>
    <div class="stats-grid stats-grid-3">
        <article class="stat-card"><span class="stat-label">MAYORS / FINAL APPROVERS</span><strong>{{ number_format($totalMayors) }}</strong><small>including existing authorized approvers</small><span class="stat-icon"><i data-lucide="landmark"></i></span></article>
        <article class="stat-card"><span class="stat-label">EMPLOYEE ACCOUNTS</span><strong>{{ number_format($totalEmployees) }}</strong><small>staff sign-in accounts</small><span class="stat-icon"><i data-lucide="user-round"></i></span></article>
        <article class="stat-card"><span class="stat-label">ACTIVE / INACTIVE</span><strong>{{ number_format($activeUsers) }} / {{ number_format($inactiveUsers) }}</strong><small>Super Admin accounts: {{ number_format($totalSuperAdmins) }}</small><span class="stat-icon"><i data-lucide="user-cog"></i></span></article>
    </div>

    <div class="content-grid dashboard-content-grid">
        <section class="panel">
            <div class="panel-heading"><div><h2>Recent administrative activity</h2><p>Account creation, role, access, and credential events</p></div><a class="text-link" href="{{ route('audit.index') }}">Audit logs <i data-lucide="arrow-right"></i></a></div>
            @forelse($recentActivities as $activity)
                <div class="application-row">
                    <span class="row-main"><b>{{ str($activity->action)->replace(['user.', '_'], ['', ' '])->headline() }}</b><small>{{ $activity->user?->name ?? 'System' }} · {{ $activity->created_at->format('M j, Y g:i A') }}</small></span>
                    <span class="status-tag status-submitted">{{ $activity->metadata['new_role'] ?? $activity->metadata['role'] ?? 'Recorded' }}</span>
                </div>
            @empty
                <p class="empty-note">No administrative account activity has been recorded yet.</p>
            @endforelse
        </section>
        <section class="panel">
            <div class="panel-heading"><div><h2>Recently created accounts</h2><p>Latest account records</p></div><a class="text-link" href="{{ route('super-admin.users.index') }}">View all <i data-lucide="arrow-right"></i></a></div>
            @forelse($recentAccounts as $account)
                <a class="application-row" href="{{ route('super-admin.users.show', $account) }}">
                    @include('shared.avatar', ['user' => $account])
                    <span class="row-main"><b>{{ $account->name }}</b><small>{{ $account->email }} · {{ $account->created_at->format('M j, Y') }}</small></span>
                    <span class="status-tag {{ $account->is_active ? 'status-approved' : 'status-disapproved' }}">{{ $account->is_active ? 'Active' : 'Inactive' }}</span>
                </a>
            @empty
                <p class="empty-note">No user accounts have been created.</p>
            @endforelse
        </section>
    </div>
@endsection
