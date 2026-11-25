<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EmailVerificationOtpService;
use App\Services\PrivilegedMfaChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        EmailVerificationOtpService $emailVerificationOtp,
        PrivilegedMfaChallengeService $mfaChallenge,
    ): RedirectResponse {
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

        $errorField = $legacyEmailInput ? 'email' : 'login';

        if ($user->isAdministrator() && ! $user->hasVerifiedEmail()) {
            if (! filled($user->email)) {
                throw ValidationException::withMessages([
                    $errorField => 'A registered email address is required before Admin sign-in can continue.',
                ]);
            }

            $emailVerificationOtp->send($user, $user->email, 'initial', $errorField);
            $request->session()->put('hris.email_verification.pending_user_id', $user->id);
            $request->session()->put('hris.email_verification.attempts', 0);
            $request->session()->put('hris.email_verification.remember', $request->boolean('remember'));

            return redirect()->route('email.verify.otp');
        }

        if (in_array($user->role, ['admin', 'mayor', 'office_head'], true)) {
            if (! filled($user->email) || ! $user->hasVerifiedEmail()) {
                throw ValidationException::withMessages([
                    $errorField => 'A verified email address is required for privileged account login.',
                ]);
            }

            $mfaChallenge->begin($user, $request, $errorField);
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
