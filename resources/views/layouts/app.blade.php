<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#17324d">
    <link rel="icon" type="image/webp" href="{{ asset('image/hrislogo.webp') }}">
    <title>@yield('title', 'Dashboard') · Camalaniugan Municipal HRMO</title>
    <script>document.documentElement.dataset.theme = localStorage.getItem('hris-theme') === 'dark' ? 'dark' : 'light';</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $currentUser = auth()->user();
    $unreadCount = $currentUser->unreadNotifications()->count();
    $roleTheme = $currentUser->canFinalApprove() ? 'mayor' : ($currentUser->isAdministrator() ? 'administrator' : $currentUser->role);
    $roleLabel = match ($roleTheme) {
        'administrator' => 'ADMIN · HR OFFICE',
        'mayor' => 'MAYOR · FINAL APPROVER',
        'office_head' => 'Office Head',
        'employee' => 'Employee / Staff',
        default => 'Municipal HRIS',
    };
@endphp
<body data-role-theme="{{ $roleTheme }}" x-data="{ sidebarOpen: false, sidebarCollapsed: false, profileOpen: false, init() { const resetCollapsedSidebar = () => { if (window.matchMedia('(max-width: 1060px)').matches) this.sidebarCollapsed = false; }; resetCollapsedSidebar(); window.addEventListener('resize', resetCollapsedSidebar, { passive: true }); } }">
    <div class="app-shell" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
        <aside id="primary-navigation" class="sidebar" :class="{ 'sidebar-open': sidebarOpen }" :inert="sidebarCollapsed" aria-label="Primary navigation">
            <a class="brand" href="{{ route('dashboard') }}" @click="sidebarOpen=false">
                <img class="brand-logo" src="{{ asset('image/hrislogo.webp') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal" width="44" height="44" decoding="async">
                <span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office</small></span>
            </a>
            <nav class="side-nav">
                <span class="nav-caption">{{ $currentUser->isEmployee() ? 'MY WORKSPACE' : ($currentUser->isOfficeHead() ? 'DEPARTMENT' : ($roleTheme === 'mayor' ? 'EXECUTIVE' : 'WORKSPACE')) }}</span>
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" @click="sidebarOpen=false" @if(request()->routeIs('dashboard')) aria-current="page" @endif><i data-lucide="layout-dashboard" aria-hidden="true"></i><span>{{ $roleTheme === 'mayor' ? 'Municipal overview' : 'Dashboard' }}</span></a>

                @if($currentUser->isAdministrator())
                    <span class="nav-caption">HR OPERATIONS</span>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="users-round" aria-hidden="true"></i><span>Employees</span></a>
                    <a href="{{ route('employee-imports.index') }}" class="{{ request()->routeIs('employee-imports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-spreadsheet" aria-hidden="true"></i><span>Employee import</span></a>
                    <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="building-2" aria-hidden="true"></i><span>Departments</span></a>
                    <a href="{{ route('positions.index') }}" class="{{ request()->routeIs('positions.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="briefcase-business" aria-hidden="true"></i><span>Positions</span></a>
                    <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="files" aria-hidden="true"></i><span>Leave management</span></a>
                    @if($currentUser->canApproveMayorLeave())
                        <a href="{{ route('leave.index', ['status' => 'Pending Authorized Final Approval']) }}" class="{{ request()->routeIs('leave.*') && request('status') === 'Pending Authorized Final Approval' ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="stamp" aria-hidden="true"></i><span>Mayor leave decisions</span></a>
                    @endif
                    <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="clock-3" aria-hidden="true"></i><span>Attendance</span></a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>Reports</span></a>
                    <a href="{{ route('leave-credits.index') }}" class="{{ request()->routeIs('leave-credits.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="wallet" aria-hidden="true"></i><span>Leave credits</span></a>
                    <a href="{{ route('settings.leave-types') }}" class="{{ request()->routeIs('settings.leave-types*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="list-checks" aria-hidden="true"></i><span>Leave requirements</span></a>
                    <a href="{{ route('office-head-availability.index') }}" class="{{ request()->routeIs('office-head-availability.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-clock" aria-hidden="true"></i><span>Office Head availability</span></a>
                    <a href="{{ route('settings.holidays') }}" class="{{ request()->routeIs('settings.holidays*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-days" aria-hidden="true"></i><span>Holiday calendar</span></a>
                    <span class="nav-caption">SYSTEM ADMINISTRATION</span>
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="contact-round" aria-hidden="true"></i><span>User & permissions</span></a>
                    <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Audit logs</span></a>
                    <a href="{{ route('settings.system') }}" class="{{ request()->routeIs('settings.system*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="settings-2" aria-hidden="true"></i><span>System settings</span></a>
                @elseif($currentUser->isEmployee())
                    <span class="nav-caption">MY RECORDS</span>
                    @if($currentUser->employee)
                        <a href="{{ route('employees.show', $currentUser->employee) }}" class="{{ request()->routeIs('employees.show') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="user-round" aria-hidden="true"></i><span>My employee record</span></a>
                    @endif
                    <span class="nav-caption">ATTENDANCE & CREDITS</span>
                    <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="clock-3" aria-hidden="true"></i><span>My attendance</span></a>
                    <a href="{{ route('my-profile.edit') }}" class="{{ request()->routeIs('my-profile.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="settings-2" aria-hidden="true"></i><span>Profile settings</span></a>
                @elseif($currentUser->isOfficeHead())
                    <span class="nav-caption">TEAM & LEAVE</span>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="users-round" aria-hidden="true"></i><span>My employees</span></a>
                    <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="files" aria-hidden="true"></i><span>Leave requests</span></a>
                    <span class="nav-caption">OPERATIONS</span>
                    <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="clock-3" aria-hidden="true"></i><span>Attendance</span></a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>Department reports</span></a>
                    <a href="{{ route('office-head-availability.index') }}" class="{{ request()->routeIs('office-head-availability.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-clock" aria-hidden="true"></i><span>Office Head availability</span></a>
                    <a href="{{ route('dashboard') }}#approval-history" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Approval history</span></a>
                @elseif($roleTheme === 'mayor')
                    <span class="nav-caption">DECISIONS</span>
                    <a href="{{ route('leave.index', ['status' => 'Pending Final Approval']) }}" class="{{ request()->routeIs('leave.*') && (! request('status') || request('status') === 'Pending Final Approval') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-check-2" aria-hidden="true"></i><span>Leave approvals</span></a>
                    <a href="{{ route('leave.index', ['status' => 'Under Alternate Office Head Review']) }}" class="{{ request()->routeIs('leave.*') && request('status') === 'Under Alternate Office Head Review' ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="user-round-check" aria-hidden="true"></i><span>Escalated requests</span></a>
                    <span class="nav-caption">MUNICIPAL OVERSIGHT</span>
                    <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="building-2" aria-hidden="true"></i><span>Departments</span></a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>HR reports</span></a>
                    <span class="nav-caption">RECORDS</span>
                    <a href="{{ route('dashboard') }}#approval-history" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Approval history</span></a>
                @elseif($currentUser->isHrmo())
                    <span class="nav-caption">PERSONNEL</span>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="users-round" aria-hidden="true"></i><span>Employees</span></a>
                    <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="building-2" aria-hidden="true"></i><span>Departments</span></a>
                    <a href="{{ route('positions.index') }}" class="{{ request()->routeIs('positions.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="briefcase-business" aria-hidden="true"></i><span>Positions</span></a>
                    <a href="{{ route('employee-imports.index') }}" class="{{ request()->routeIs('employee-imports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-spreadsheet" aria-hidden="true"></i><span>Employee import</span></a>
                    <span class="nav-caption">HR OPERATIONS</span>
                    <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="files" aria-hidden="true"></i><span>Leave management</span></a>
                    <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="clock-3" aria-hidden="true"></i><span>Attendance</span></a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>Reports</span></a>
                    <a href="{{ route('leave-credits.index') }}" class="{{ request()->routeIs('leave-credits.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="wallet" aria-hidden="true"></i><span>Leave credits</span></a>
                    <a href="{{ route('office-head-availability.index') }}" class="{{ request()->routeIs('office-head-availability.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-clock" aria-hidden="true"></i><span>Office Head availability</span></a>
                    <a href="{{ route('settings.leave-types') }}" class="{{ request()->routeIs('settings.leave-types*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="list-checks" aria-hidden="true"></i><span>Leave requirements</span></a>
                    <a href="{{ route('settings.holidays') }}" class="{{ request()->routeIs('settings.holidays*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-days" aria-hidden="true"></i><span>Holiday calendar</span></a>
                    <span class="nav-caption">GOVERNANCE · READ ONLY</span>
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="contact-round" aria-hidden="true"></i><span>User management · view only</span></a>
                    <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Audit logs · view only</span></a>
                @else
                    @if($currentUser->canFinalApprove())
                        <span class="nav-caption">DECISIONS</span>
                        <a href="{{ route('leave.index', ['status' => 'Pending Final Approval']) }}" class="{{ request()->routeIs('leave.*') && request('status') === 'Pending Final Approval' ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-check-2" aria-hidden="true"></i><span>Leave approvals</span></a>
                    @endif
                    <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="clock-3" aria-hidden="true"></i><span>Attendance</span></a>
                    <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="files" aria-hidden="true"></i><span>Leave applications</span></a>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="users-round" aria-hidden="true"></i><span>Employees</span></a>
                    <a href="{{ route('employee-imports.index') }}" class="{{ request()->routeIs('employee-imports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-spreadsheet" aria-hidden="true"></i><span>Employee import</span></a>
                    <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="building-2" aria-hidden="true"></i><span>Departments</span></a>
                    <a href="{{ route('positions.index') }}" class="{{ request()->routeIs('positions.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="briefcase-business" aria-hidden="true"></i><span>Positions</span></a>
                    <a href="{{ route('leave-credits.index') }}" class="{{ request()->routeIs('leave-credits.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="wallet" aria-hidden="true"></i><span>Leave credits</span></a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>Reports</span></a>
                    <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="contact-round" aria-hidden="true"></i><span>User management</span></a>
                    <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Audit logs</span></a>
                @endif
                <span class="nav-caption">ACCOUNT</span>
                @if($currentUser->employee)
                    <a href="{{ route('leave.create') }}" class="{{ request()->routeIs('leave.create') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="file-plus-2" aria-hidden="true"></i><span>Apply for leave</span></a>
                    <a href="{{ route('leave.index', ['mine' => 1]) }}" class="{{ request()->routeIs('leave.index', 'leave.show', 'leave.edit') && request()->boolean('mine') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="files" aria-hidden="true"></i><span>My leave applications</span></a>
                    <a href="{{ route('leave-credits.index', ['mine' => 1]) }}" class="{{ request()->routeIs('leave-credits.*') && request()->boolean('mine') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="wallet" aria-hidden="true"></i><span>My leave credits</span></a>
                @endif
                <a href="{{ route('account.profile') }}" class="{{ request()->routeIs('account.profile') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="user-round" aria-hidden="true"></i><span>My profile</span></a>
                <a href="{{ route('notifications.index') }}" class="{{ request()->routeIs('notifications.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                    <i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span>
                    @if($unreadCount)<span class="nav-badge" aria-label="{{ $unreadCount }} unread notifications">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                </a>
            </nav>
            <div class="sidebar-bottom">
                <div class="user-mini">
                    @include('shared.avatar', ['user' => $currentUser])
                    <span><strong>{{ $currentUser->name }}</strong><small>{{ $roleLabel }}</small></span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit"><i data-lucide="log-out" aria-hidden="true"></i> Sign out</button></form>
            </div>
        </aside>
        <button class="sidebar-scrim" type="button" x-show="sidebarOpen" @click="sidebarOpen=false" aria-label="Close navigation" x-cloak></button>
        <main class="main-area">
            <div class="role-theme-band" aria-hidden="true"></div>
            <header class="topbar">
                <button class="sidebar-collapse-toggle" type="button" @click="sidebarCollapsed = !sidebarCollapsed" :aria-expanded="(!sidebarCollapsed).toString()" :aria-label="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'" :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'">
                    <i data-lucide="panel-left-close" aria-hidden="true"></i>
                </button>
                <button class="mobile-menu" type="button" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()" aria-controls="primary-navigation" aria-label="Toggle navigation">
                    <i data-lucide="menu" aria-hidden="true"></i>
                </button>
                <div class="breadcrumbs"><span>Camalaniugan</span><i data-lucide="chevron-right" aria-hidden="true"></i><strong>@yield('title', 'Dashboard')</strong></div>
                <div class="topbar-right">
                    <span class="topbar-date"><i data-lucide="calendar-days" aria-hidden="true"></i>{{ now()->format('D, M j, Y') }}</span>
                    <button class="theme-toggle" type="button" data-theme-toggle aria-pressed="false" aria-label="Switch to dark mode">
                        <i class="theme-icon-moon" data-lucide="moon" aria-hidden="true"></i>
                        <i class="theme-icon-sun" data-lucide="sun" aria-hidden="true"></i>
                        <span>Dark mode</span>
                    </button>
                    <a class="icon-button" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unreadCount ? ', '.$unreadCount.' unread' : '' }}">
                        <i data-lucide="bell" aria-hidden="true"></i>
                        @if($unreadCount)<span class="notification-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                    </a>
                    <div class="profile-menu" @click.outside="profileOpen=false">
                        <button class="topbar-user" type="button" @click="profileOpen=!profileOpen" :aria-expanded="profileOpen.toString()">
                            @include('shared.avatar', ['user' => $currentUser, 'size' => 'avatar-small'])
                            <span class="topbar-user-info"><b>{{ $currentUser->name }}</b><small>{{ $roleLabel }}</small></span>
                            <i data-lucide="chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="profile-dropdown" x-show="profileOpen" x-cloak>
                            <a class="profile-dropdown-identity" href="{{ route('account.profile') }}">
                                @include('shared.avatar', ['user' => $currentUser])
                                <span><strong>{{ $currentUser->name }}</strong><small>{{ $roleLabel }}</small></span>
                            </a>
                            <a href="{{ route('account.profile') }}"><i data-lucide="user-round" aria-hidden="true"></i> My profile</a>
                            <a href="{{ route('account.profile.edit') }}"><i data-lucide="user-round-pen" aria-hidden="true"></i> Edit profile</a>
                            <a href="{{ route('account.profile') }}#change-password"><i data-lucide="key-round" aria-hidden="true"></i> Change password</a>
                            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><i data-lucide="log-out" aria-hidden="true"></i> Sign out</button></form>
                        </div>
                    </div>
                </div>
            </header>
            <section class="page-content">
                @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
                @if($errors->any())<div class="alert alert-error" role="alert"><strong>Please check the form.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                @yield('content')
            </section>
        </main>
    </div>
</body>
</html>
