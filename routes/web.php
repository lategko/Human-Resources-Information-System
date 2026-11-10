<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DeductionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\LeaveCreditController;
use App\Http\Controllers\LeaveReviewController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkingDayController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/my-profile/edit', [EmployeeController::class, 'editPersonal'])->middleware('role:employee')->name('my-profile.edit');
    Route::put('/my-profile', [EmployeeController::class, 'updatePersonal'])->middleware('role:employee')->name('my-profile.update');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('/attendance/template', [AttendanceController::class, 'template'])->middleware('role:admin,hrmo')->name('attendance.template');
    Route::post('/attendance/import', [AttendanceController::class, 'import'])->middleware('role:admin,hrmo')->name('attendance.import');
    Route::get('/attendance/imports/{biometricImport}', [AttendanceController::class, 'importDetails'])->middleware('role:admin,hrmo')->name('attendance.imports.show');
    Route::post('/attendance/{attendance}/correct', [AttendanceController::class, 'correct'])->middleware('role:admin,hrmo')->name('attendance.correct');
    Route::middleware('role:admin,hrmo,office_head')->group(function () {
        Route::get('/deductions', [DeductionController::class, 'index'])->name('deductions.index');
        Route::post('/deductions/attendance/{attendance}/review', [DeductionController::class, 'review'])->middleware('role:admin,hrmo')->name('deductions.review');
        Route::post('/deductions/{deduction}/approve', [DeductionController::class, 'approve'])->middleware('role:admin')->name('deductions.approve');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{type}/csv', [ReportController::class, 'export'])->name('reports.csv');
    });

    Route::get('/leave-applications', [LeaveApplicationController::class, 'index'])->name('leave.index');
    Route::get('/leave-applications/create', [LeaveApplicationController::class, 'create'])->name('leave.create');
    Route::post('/leave-applications', [LeaveApplicationController::class, 'store'])->name('leave.store');
    Route::get('/leave-applications/working-days', WorkingDayController::class)->name('leave.working-days');
    Route::get('/leave-applications/{application}/edit', [LeaveApplicationController::class, 'edit'])->name('leave.edit');
    Route::get('/leave-applications/{application}', [LeaveApplicationController::class, 'show'])->name('leave.show');
    Route::put('/leave-applications/{application}', [LeaveApplicationController::class, 'update'])->name('leave.update');
    Route::get('/leave-applications/{application}/print', [LeaveApplicationController::class, 'print'])->name('leave.print');
    Route::get('/leave-applications/{application}/documents/{document}', [LeaveApplicationController::class, 'document'])->name('leave.documents');
    Route::post('/leave-applications/{application}/review', LeaveReviewController::class)->name('leave.review');
    Route::post('/leave-applications/{application}/cancel', [LeaveApplicationController::class, 'cancel'])->name('leave.cancel');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->middleware('role:admin,hrmo')->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('role:admin,hrmo')->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware('role:admin,hrmo')->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('role:admin,hrmo')->name('employees.update');
    Route::post('/employees/{employee}/archive', [EmployeeController::class, 'archive'])->middleware('role:admin')->name('employees.archive');

    Route::middleware('role:admin,hrmo')->group(function () {
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
        Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
        Route::put('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
        Route::get('/settings/leave-types', [LeaveTypeController::class, 'index'])->name('settings.leave-types');
        Route::put('/settings/leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('settings.leave-types.update');
        Route::get('/settings/holidays', [HolidayController::class, 'index'])->name('settings.holidays');
        Route::post('/settings/holidays', [HolidayController::class, 'store'])->name('settings.holidays.store');
        Route::put('/settings/holidays/{holiday}', [HolidayController::class, 'update'])->name('settings.holidays.update');
        Route::get('/leave-credits', [LeaveCreditController::class, 'index'])->name('leave-credits.index');
        Route::post('/leave-credits', [LeaveCreditController::class, 'store'])->name('leave-credits.store');
        Route::post('/leave-credits/{credit}/adjust', [LeaveCreditController::class, 'adjust'])->name('leave-credits.adjust');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/settings/system', [SystemSettingsController::class, 'index'])->name('settings.system');
        Route::put('/settings/system', [SystemSettingsController::class, 'update'])->name('settings.system.update');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
});
