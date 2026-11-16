<?php

namespace App\Providers;

use App\Models\Employee;
use App\Services\LeaveCreditAccountService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Employee::created(function (Employee $employee): void {
            app(LeaveCreditAccountService::class)->ensureRegularAccounts($employee);
        });
    }
}
