<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
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

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $legacyEmailInput = ! $request->filled('login') && $request->has('email');
        $request->merge(['login' => $request->input('login', $request->input('email'))]);
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $login = Str::lower(trim($credentials['login']));
        $user = str_contains($login, '@')
            ? User::query()->whereRaw('LOWER(email) = ?', [$login])->first()
            : User::query()->whereRaw('LOWER(username) = ?', [$login])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)
            || ! in_array($user->role, User::VALID_ROLES, true)) {
            Auth::logout();

            throw ValidationException::withMessages([
                $legacyEmailInput ? 'email' : 'login' => 'The provided username/email and password do not match our records.',
            ]);
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
