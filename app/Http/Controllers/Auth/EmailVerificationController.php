<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(EmailVerificationRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.profile')->with('status', 'Your email address is already verified.');
        }

        $request->fulfill();
        $auditLogger->record('account.email_verified', $request->user());

        return redirect()->route('account.profile')->with('success', 'Your email address has been verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->email) {
            return back()->withErrors(['email' => 'Add an email address before requesting verification.']);
        }
        if ($user->hasVerifiedEmail()) {
            return back()->with('status', 'Your email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'A verification link has been sent to your email address.');
    }
}
