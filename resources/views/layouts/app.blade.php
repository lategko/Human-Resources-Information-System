<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1478B9">
    <link rel="icon" type="image/jpeg" href="{{ asset('image/hrislogo.jpg') }}">
    <title>@yield('title', 'Dashboard') · Camalaniugan Municipal HRMO</title>
    @vite(['resources/js/app.js'])
</head>
<body x-data="{ sidebarOpen: false, profileOpen: false }">
    @php
        $currentUser = auth()->user();
        $unreadCount = $currentUser->unreadNotifications()->count();
    @endphp
    <div class="app-shell">
        <aside id="primary-navigation" class="sidebar" :class="{ 'sidebar-open': sidebarOpen }" aria-label="Primary navigation">
            <a class="brand" href="{{ route('dashboard') }}" @click="sidebarOpen=false">
                <img class="brand-logo" src="{{ asset('image/hrislogo.jpg') }}" alt="Official Municipality of Camalaniugan Human Resource Management Office seal">
                <span><strong>Municipality of Camalaniugan</strong><small>Human Resource Management Office</small></span>
            </a>
            <nav class="side-nav">
                <span class="nav-caption">WORKSPACE</span>
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" @click="sidebarOpen=false" @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                    <i data-lucide="layout-dashboard" aria-hidden="true"></i><span>Overview</span>
                </a>

                @if($currentUser->isEmployee())
                    <a href="{{ route('employees.show', $currentUser->employee) }}" class="{{ request()->routeIs('employees.show') ? 'active' : '' }}" @click="sidebarOpen=false">
                        <i data-lucide="user-round" aria-hidden="true"></i><span>My profile</span>
                    </a>
                    <a href="{{ route('leave.create') }}" class="{{ request()->routeIs('leave.create') ? 'active' : '' }}" @click="sidebarOpen=false">
                        <i data-lucide="file-plus-2" aria-hidden="true"></i><span>Apply for leave</span>
                    </a>
                @endif
                <a href="{{ route('attendance.index') }}" class="{{ request()->routeIs('attendance.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                    <i data-lucide="clock-3" aria-hidden="true"></i><span>{{ $currentUser->isEmployee() ? 'My attendance' : 'Attendance' }}</span>
                </a>
                @if(! $currentUser->isEmployee())
                    <a href="{{ route('deductions.index') }}" class="{{ request()->routeIs('deductions.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                        <i data-lucide="receipt-text" aria-hidden="true"></i><span>Deduction monitoring</span>
                    </a>
                    <a href="{{ route('reports.index') }}" class="{{ request()->routeIs('reports.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                        <i data-lucide="chart-no-axes-column-increasing" aria-hidden="true"></i><span>Reports</span>
                    </a>
                @endif
                <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') && !request()->routeIs('leave.create') ? 'active' : '' }}" @click="sidebarOpen=false">
                    <i data-lucide="files" aria-hidden="true"></i><span>{{ $currentUser->isEmployee() ? 'My applications' : 'Leave applications' }}</span>
                </a>

                @if($currentUser->canManageHr())
                    <details class="nav-group" @if(request()->routeIs('employees.*', 'departments.*', 'positions.*', 'settings.leave-types', 'settings.holidays', 'leave-credits.*')) open @endif>
                        <summary><i data-lucide="users-round" aria-hidden="true"></i><span>Human resources</span><i class="nav-chevron" data-lucide="chevron-down" aria-hidden="true"></i></summary>
                        <div class="nav-group-links">
                            <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="users" aria-hidden="true"></i><span>Employees</span></a>
                            <a href="{{ route('departments.index') }}" class="{{ request()->routeIs('departments.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="building-2" aria-hidden="true"></i><span>Departments</span></a>
                            <a href="{{ route('positions.index') }}" class="{{ request()->routeIs('positions.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="briefcase-business" aria-hidden="true"></i><span>Positions</span></a>
                            <a href="{{ route('settings.leave-types') }}" class="{{ request()->routeIs('settings.leave-types*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="list-checks" aria-hidden="true"></i><span>Leave requirements</span></a>
                            <a href="{{ route('settings.holidays') }}" class="{{ request()->routeIs('settings.holidays*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="calendar-days" aria-hidden="true"></i><span>Holiday calendar</span></a>
                            <a href="{{ route('leave-credits.index') }}" class="{{ request()->routeIs('leave-credits.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="wallet" aria-hidden="true"></i><span>Leave credits</span></a>
                        </div>
                    </details>
                @elseif($currentUser->isOfficeHead())
                    <span class="nav-caption">DEPARTMENT</span>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                        <i data-lucide="users-round" aria-hidden="true"></i><span>My department</span>
                    </a>
                @endif

                @if($currentUser->isAdministrator())
                    <details class="nav-group" @if(request()->routeIs('users.*', 'settings.system', 'audit.*')) open @endif>
                        <summary><i data-lucide="shield-check" aria-hidden="true"></i><span>Administration</span><i class="nav-chevron" data-lucide="chevron-down" aria-hidden="true"></i></summary>
                        <div class="nav-group-links">
                            <a href="{{ route('settings.system') }}" class="{{ request()->routeIs('settings.system*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="settings-2" aria-hidden="true"></i><span>System settings</span></a>
                            <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="contact-round" aria-hidden="true"></i><span>User accounts</span></a>
                            <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'active' : '' }}" @click="sidebarOpen=false"><i data-lucide="history" aria-hidden="true"></i><span>Audit logs</span></a>
                        </div>
                    </details>
                @endif

                <span class="nav-caption">ACCOUNT</span>
                <a href="{{ route('notifications.index') }}" class="{{ request()->routeIs('notifications.*') ? 'active' : '' }}" @click="sidebarOpen=false">
                    <i data-lucide="bell" aria-hidden="true"></i><span>Notifications</span>
                    @if($unreadCount)<span class="nav-badge" aria-label="{{ $unreadCount }} unread notifications">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                </a>
            </nav>
            <div class="sidebar-bottom">
                <div class="user-mini">
                    <span class="avatar">{{ strtoupper(substr($currentUser->name, 0, 1)) }}</span>
                    <span><strong>{{ $currentUser->name }}</strong><small>{{ str_replace('_', ' ', ucfirst($currentUser->role)) }}{{ $currentUser->canFinalApprove() ? ' · Final approver' : '' }}</small></span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit"><i data-lucide="log-out" aria-hidden="true"></i> Sign out</button></form>
            </div>
        </aside>
        <button class="sidebar-scrim" type="button" x-show="sidebarOpen" @click="sidebarOpen=false" aria-label="Close navigation" x-cloak></button>
        <main class="main-area">
            <header class="topbar">
                <button class="mobile-menu" type="button" @click="sidebarOpen = !sidebarOpen" :aria-expanded="sidebarOpen.toString()" aria-controls="primary-navigation" aria-label="Toggle navigation">
                    <i data-lucide="menu" aria-hidden="true"></i>
                </button>
                <div class="breadcrumbs"><span>Camalaniugan</span><i data-lucide="chevron-right" aria-hidden="true"></i><strong>@yield('title', 'Dashboard')</strong></div>
                <div class="topbar-right">
                    <span class="topbar-date"><i data-lucide="calendar-days" aria-hidden="true"></i>{{ now()->format('D, M j, Y') }}</span>
                    <a class="icon-button" href="{{ route('notifications.index') }}" aria-label="Notifications{{ $unreadCount ? ', '.$unreadCount.' unread' : '' }}">
                        <i data-lucide="bell" aria-hidden="true"></i>
                        @if($unreadCount)<span class="notification-count">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                    </a>
                    <div class="profile-menu" @click.outside="profileOpen=false">
                        <button class="topbar-user" type="button" @click="profileOpen=!profileOpen" :aria-expanded="profileOpen.toString()">
                            <span class="avatar avatar-small">{{ strtoupper(substr($currentUser->name, 0, 1)) }}</span>
                            <span>{{ $currentUser->name }}</span>
                            <i data-lucide="chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="profile-dropdown" x-show="profileOpen" x-cloak>
                            <div><strong>{{ $currentUser->name }}</strong><small>{{ str_replace('_', ' ', ucfirst($currentUser->role)) }}</small></div>
                            @if($currentUser->isEmployee())
                                <a href="{{ route('employees.show', $currentUser->employee) }}"><i data-lucide="user-round" aria-hidden="true"></i> My profile</a>
                                <a href="{{ route('my-profile.edit') }}"><i data-lucide="settings-2" aria-hidden="true"></i> Profile settings</a>
                            @endif
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
