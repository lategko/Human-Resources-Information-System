<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · Camalaniugan HRIS</title>
    @vite(['resources/js/app.js'])
</head>
<body x-data="{ sidebarOpen: false }">
    <div class="app-shell">
        <aside class="sidebar" :class="{ 'sidebar-open': sidebarOpen }">
            <a class="brand" href="{{ route('dashboard') }}">
                <span class="brand-mark">C</span>
                <span><strong>Camalaniugan</strong><small>Human Resource Information System</small></span>
            </a>
            <div class="nav-caption">WORKSPACE</div>
            <nav class="side-nav">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}"><span>▦</span> Overview</a>
                @if(auth()->user()->isEmployee())
                    <a href="{{ route('employees.show', auth()->user()->employee) }}"><span>♙</span> My profile</a>
                    <a href="{{ route('leave.create') }}"><span>＋</span> Apply for leave</a>
                @endif
                <a href="{{ route('attendance.index') }}"><span>◷</span> Attendance</a>
                @if(auth()->user()->role !== 'employee')
                    <a href="{{ route('deductions.index') }}"><span>◷</span> Deduction monitoring</a>
                    <a href="{{ route('reports.index') }}"><span>▤</span> Reports</a>
                @endif
                <a href="{{ route('leave.index') }}" class="{{ request()->routeIs('leave.*') && !request()->routeIs('leave.create') ? 'active' : '' }}"><span>▤</span> Leave applications</a>
                @if(auth()->user()->canManageHr() || auth()->user()->isAdministrator())
                    <div class="nav-caption">HUMAN RESOURCES</div>
                    <a href="{{ route('employees.index') }}" class="{{ request()->routeIs('employees.*') ? 'active' : '' }}"><span>♧</span> Employees</a>
                    <a href="{{ route('departments.index') }}"><span>⌂</span> Departments</a>
                    <a href="{{ route('positions.index') }}"><span>▣</span> Positions</a>
                    <a href="{{ route('settings.leave-types') }}"><span>⚙</span> Leave requirements</a>
                    <a href="{{ route('settings.holidays') }}"><span>◷</span> Holiday calendar</a>
                    <a href="{{ route('leave-credits.index') }}"><span>◉</span> Leave credits</a>
                    @if(auth()->user()->isAdministrator())
                        <a href="{{ route('settings.system') }}"><span>⚙</span> System settings</a>
                        <a href="{{ route('users.index') }}"><span>♙</span> User accounts</a>
                        <a href="{{ route('audit.index') }}"><span>◉</span> Audit logs</a>
                    @endif
                @elseif(auth()->user()->isOfficeHead())
                    <div class="nav-caption">DEPARTMENT</div>
                    <a href="{{ route('employees.index') }}"><span>♧</span> My department</a>
                @endif
                <div class="nav-caption">ACCOUNT</div>
                <a href="{{ route('notifications.index') }}"><span>◉</span> Notifications
                    @if(auth()->user()->unreadNotifications()->count())
                        <span class="nav-badge">{{ auth()->user()->unreadNotifications()->count() }}</span>
                    @endif
                </a>
            </nav>
            <div class="sidebar-bottom">
                <div class="user-mini"><span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <span><strong>{{ auth()->user()->name }}</strong><small>{{ str_replace('_', ' ', ucfirst(auth()->user()->role)) }}</small></span>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit">Sign out <span>↗</span></button></form>
            </div>
        </aside>
        <div class="sidebar-scrim" x-show="sidebarOpen" @click="sidebarOpen=false" x-cloak></div>
        <main class="main-area">
            <header class="topbar">
                <button class="mobile-menu" type="button" @click="sidebarOpen = !sidebarOpen" aria-label="Toggle navigation">☰</button>
                <div class="breadcrumbs">Camalaniugan <span>/</span> <strong>@yield('title', 'Dashboard')</strong></div>
                <div class="topbar-right">
                    <a class="icon-button" href="{{ route('notifications.index') }}" aria-label="Notifications">◉
                        @if(auth()->user()->unreadNotifications()->count())<i></i>@endif
                    </a>
                    <div class="topbar-user"><span class="avatar avatar-small">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span>{{ auth()->user()->name }}</span></div>
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
