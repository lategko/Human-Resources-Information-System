<?php

use App\Http\Controllers\AccountProfileController;
use App\Http\Controllers\AccountSetupController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\LeaveApplicationController;
use App\Http\Controllers\LeaveCreditController;
use App\Http\Controllers\LeaveReviewController;
use App\Http\Controllers\LeaveTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OfficeHeadAvailabilityController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkingDayController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/account/setup', [AccountSetupController::class, 'create'])->name('account.setup');
    Route::put('/account/setup', [AccountSetupController::class, 'update'])->name('account.setup.update');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed', 'throttle:6,1')
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::middleware(['auth', 'active', 'account.setup'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/account/profile', AccountProfileController::class)->name('account.profile');
    Route::get('/account/profile/edit', [AccountProfileController::class, 'edit'])->name('account.profile.edit');
    Route::put('/account/profile', [AccountProfileController::class, 'update'])->name('account.profile.update');
    Route::post('/account/profile/photo', [AccountProfileController::class, 'updatePhoto'])->name('account.profile.photo');
    Route::delete('/account/profile/photo', [AccountProfileController::class, 'deletePhoto'])->name('account.profile.photo.delete');
    Route::put('/account/profile/password', [AccountProfileController::class, 'updatePassword'])->name('account.profile.password');
    Route::get('/my-profile/edit', [EmployeeController::class, 'editPersonal'])->name('my-profile.edit');
    Route::put('/my-profile', [EmployeeController::class, 'updatePersonal'])->name('my-profile.update');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance/time-in', [AttendanceController::class, 'timeIn'])->name('attendance.time-in');
    Route::post('/attendance/time-out', [AttendanceController::class, 'timeOut'])->name('attendance.time-out');
    Route::post('/attendance/exceptions', [AttendanceController::class, 'storeException'])->name('attendance.exceptions.store');
    Route::post('/attendance/exceptions/{exception}/review', [AttendanceController::class, 'reviewException'])->middleware('role:admin')->name('attendance.exceptions.review');
    Route::post('/attendance/workplaces', [AttendanceController::class, 'storeWorkplace'])->middleware('role:admin')->name('attendance.workplaces.store');
    Route::put('/attendance/workplaces/{workplace}', [AttendanceController::class, 'updateWorkplace'])->middleware('role:admin')->name('attendance.workplaces.update');
    Route::get('/attendance/workplaces/geocode/search', [AttendanceController::class, 'searchWorkplaceLocations'])->middleware(['role:admin', 'throttle:30,1'])->name('attendance.workplaces.geocode.search');
    Route::get('/attendance/workplaces/geocode/reverse', [AttendanceController::class, 'reverseWorkplaceLocation'])->middleware(['role:admin', 'throttle:30,1'])->name('attendance.workplaces.geocode.reverse');
    Route::middleware('can:manage,App\Models\AttendanceWorkplaceAssignment')->group(function () {
        Route::get('/attendance/workplaces/department-preview', [AttendanceController::class, 'departmentWorkplaceAssignmentPreview'])
            ->middleware('throttle:60,1')
            ->name('attendance.workplaces.department-preview');
        Route::get('/attendance/workplaces/individual-search', [AttendanceController::class, 'searchWorkplaceAssignmentEmployees'])
            ->middleware('throttle:60,1')
            ->name('attendance.workplaces.individual-search');
        Route::post('/attendance/workplaces/department-assignment', [AttendanceController::class, 'assignDepartmentWorkplace'])->name('attendance.workplaces.assign-department');
        Route::post('/attendance/workplaces/bulk-assignment', [AttendanceController::class, 'bulkAssignWorkplaces'])->name('attendance.workplaces.assign-bulk');
        Route::post('/attendance/employees/{employee}/workplace', [AttendanceController::class, 'assignWorkplace'])->name('attendance.employees.workplace');
    });
    Route::post('/attendance/{attendance}/correct', [AttendanceController::class, 'correct'])->middleware('role:admin')->name('attendance.correct');
    Route::post('/attendance/schedules', [AttendanceController::class, 'storeSchedule'])->middleware('role:admin')->name('attendance.schedules.store');
    Route::patch('/attendance/schedules/{workSchedule}/end', [AttendanceController::class, 'endSchedule'])->middleware('role:admin')->name('attendance.schedules.end');
    Route::middleware('role:admin,office_head,mayor')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/leave-credits/{report}/csv', [ReportController::class, 'exportLeaveCredits'])->name('reports.leave-credit.csv');
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
    Route::get('/leave-credits', [LeaveCreditController::class, 'index'])->name('leave-credits.index');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('role:admin')->name('audit.index');
    Route::get('/users', [UserController::class, 'index'])->middleware('role:admin')->name('users.index');

    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/search', [EmployeeController::class, 'search'])
        ->middleware(['role:admin,office_head,mayor', 'throttle:60,1'])
        ->name('employees.search');
    Route::get('/employees/create', [EmployeeController::class, 'create'])->middleware('role:admin')->name('employees.create');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('role:admin')->name('employees.store');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware('role:admin')->name('employees.edit');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->middleware('role:admin')->name('employees.update');
    Route::post('/employees/{employee}/archive', [EmployeeController::class, 'archive'])->middleware('role:admin')->name('employees.archive');

    Route::middleware('role:admin')->group(function () {
        Route::get('/employee-imports', [EmployeeImportController::class, 'index'])->name('employee-imports.index');
        Route::post('/employee-imports', [EmployeeImportController::class, 'store'])->name('employee-imports.store');
        Route::get('/employee-imports/{employeeImport}', [EmployeeImportController::class, 'show'])->name('employee-imports.show');
        Route::put('/employee-imports/{employeeImport}/mapping', [EmployeeImportController::class, 'updateMapping'])->name('employee-imports.mapping');
        Route::put('/employee-imports/{employeeImport}/rows/{employeeImportRow}/review', [EmployeeImportController::class, 'reviewRow'])->name('employee-imports.rows.review');
        Route::put('/employee-imports/{employeeImport}/rows/{employeeImportRow}/role', [EmployeeImportController::class, 'reviewImportedRole'])->name('employee-imports.rows.role');
        Route::post('/employee-imports/{employeeImport}/confirm', [EmployeeImportController::class, 'confirm'])->name('employee-imports.confirm');
        Route::get('/employee-imports/{employeeImport}/source', [EmployeeImportController::class, 'download'])->name('employee-imports.source');
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
        Route::post('/leave-credits', [LeaveCreditController::class, 'store'])->name('leave-credits.store');
        Route::post('/leave-credits/{credit}/adjust', [LeaveCreditController::class, 'adjust'])->name('leave-credits.adjust');
    });

    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('role:admin,office_head,mayor')->name('departments.index');
    Route::middleware('role:admin,office_head')->group(function () {
        Route::get('/office-head-availability', [OfficeHeadAvailabilityController::class, 'index'])->name('office-head-availability.index');
        Route::post('/office-head-availability', [OfficeHeadAvailabilityController::class, 'store'])->name('office-head-availability.store');
    });
    Route::middleware('role:admin')->group(function () {
        Route::put('/office-head-availability/{availability}', [OfficeHeadAvailabilityController::class, 'update'])
            ->name('office-head-availability.update');
        Route::post('/office-head-availability/{availability}/cancel', [OfficeHeadAvailabilityController::class, 'cancel'])
            ->name('office-head-availability.cancel');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/settings/system', [SystemSettingsController::class, 'index'])->name('settings.system');
        Route::put('/settings/system', [SystemSettingsController::class, 'update'])->name('settings.system.update');
        Route::post('/settings/system/backups', [DatabaseBackupController::class, 'store'])->name('settings.system.backups.store');
        Route::get('/settings/system/backups/{backup}/download', [DatabaseBackupController::class, 'download'])
            ->whereUuid('backup')
            ->name('settings.system.backups.download');
        Route::post('/settings/system/backups/{backup}/restore', [DatabaseBackupController::class, 'restore'])
            ->whereUuid('backup')
            ->name('settings.system.backups.restore');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/activation-link', [UserController::class, 'sendActivationLink'])->name('users.activation-link');
        Route::post('/users/{user}/temporary-credential', [UserController::class, 'generateTemporaryCredential'])->name('users.temporary-credential');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
});
