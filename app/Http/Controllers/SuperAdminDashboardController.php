<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\View\View;

class SuperAdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('super-admin.dashboard', [
            'totalUsers' => User::count(),
            'totalSuperAdmins' => User::where('role', 'super_admin')->count(),
            'totalAdmins' => User::where('role', 'admin')->where('can_final_approve', false)->count(),
            'totalHrmo' => User::where('role', 'hrmo')->count(),
            'totalOfficeHeads' => User::where('role', 'office_head')->count(),
            'totalMayors' => User::where(fn ($users) => $users
                ->where('role', 'mayor')
                ->orWhere(fn ($legacy) => $legacy->where('role', 'admin')->where('can_final_approve', true)))->count(),
            'totalEmployees' => User::where('role', 'employee')->count(),
            'activeUsers' => User::where('is_active', true)->count(),
            'inactiveUsers' => User::where('is_active', false)->count(),
            'recentAccounts' => User::query()->latest()->limit(8)->get(),
            'recentActivities' => AuditLog::query()
                ->with('user:id,name')
                ->whereIn('action', [
                    'user.created',
                    'user.updated',
                    'user.role_changed',
                    'user.status_changed',
                    'user.credentials_reset',
                ])
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }
}
