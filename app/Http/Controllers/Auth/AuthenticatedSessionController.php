<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Notifications\PrivilegedLoginOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $legacyEmailInput = ! $request->filled('login') && $request->has('email');
        $loginInput = $request->filled('login') ? $request->input('login') : $request->input('email');
        $request->merge(['login' => $loginInput]);
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Enter your username or email address.',
            'password.required' => 'Enter your password.',
        ]);
        $login = Str::lower(trim($credentials['login']));
        $user = str_contains($login, '@')
            ? User::query()->whereRaw('LOWER(email) = ?', [$login])->first()
            : User::query()->whereRaw('LOWER(username) = ?', [$login])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)
            || ! $user->is_active || ! in_array($user->role, User::VALID_ROLES, true)) {
            Auth::logout();

            throw ValidationException::withMessages([
                ($legacyEmailInput ? 'email' : 'login') => 'The provided username/email and password do not match our records.',
            ]);
        }

        if (in_array($user->role, ['admin', 'mayor', 'office_head'], true)) {
            if (! filled($user->email) || ! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    ($legacyEmailInput ? 'email' : 'login') => 'A verified email address is required for privileged account login.',
                ]);
            }

            $code = (string) random_int(100000, 999999);
            Cache::put('hris:mfa:otp:'.$user->id, Hash::make($code), now()->addMinutes(10));
            $request->session()->put('hris.mfa.pending_user_id', $user->id);
            $request->session()->put('hris.mfa.attempts', 0);
            $request->session()->put('hris.mfa.remember', $request->boolean('remember'));
            $request->session()->save();

            try {
                $user->notify(new PrivilegedLoginOtp($code));
            } catch (Throwable $exception) {
                Cache::forget('hris:mfa:otp:'.$user->id);
                $request->session()->forget('hris.mfa.pending_user_id');
                $request->session()->forget('hris.mfa.remember');
                Log::warning('Privileged MFA notification could not be dispatched.', [
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'ip_address' => $request->ip(),
                    'exception' => $exception->getMessage(),
                ]);
                throw ValidationException::withMessages([
                    ($legacyEmailInput ? 'email' : 'login') => 'The verification message could not be sent. Please try again later.',
                ]);
            }

            return redirect()->route('mfa.verify');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $auditLogger->record('auth.login');

        return redirect()->route(! $user->isAdministrator()
            && ($user->must_change_password || ! $user->account_setup_completed)
            ? 'account.setup'
            : 'dashboard');
    }

    public function destroy(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $auditLogger->record('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
