<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $email = (string) $request->input('email');
        $knownAccount = User::query()->whereRaw('LOWER(email) = ?', [$email])->exists();
        try {
            Password::sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            Log::warning('Password reset notification could not be dispatched.', [
                'ip_address' => $request->ip(),
            ]);
        }
        if (! $knownAccount) {
            Hash::check(Str::random(40), Hash::make(Str::random(40)));
        }

        return back()->with(
            'status',
            'If an account matches this email address, a password setup link will be sent.'
        );
    }
}
