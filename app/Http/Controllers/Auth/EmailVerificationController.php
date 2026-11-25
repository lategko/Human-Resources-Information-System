<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use App\Services\PrivilegedMfaChallengeService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function initialChallenge(Request $request, EmailVerificationOtpService $emailOtp): View|RedirectResponse
    {
        $user = $this->pendingAdmin($request, $emailOtp);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'login' => 'The email verification request expired. Sign in again to request a new code.',
            ]);
        }

        return view('auth.email-verification-challenge', [
            'email' => $user->email,
            'resendCooldownSeconds' => $emailOtp->resendCooldownSeconds($user, 'initial'),
        ]);
    }

    public function verifyInitial(
        Request $request,
        EmailVerificationOtpService $emailOtp,
        PrivilegedMfaChallengeService $mfaChallenge,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:6'],
        ]);
        $user = $this->pendingAdmin($request, $emailOtp);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'login' => 'The email verification request expired. Sign in again to request a new code.',
            ]);
        }

        $pendingEmail = $emailOtp->pendingEmail($user, 'initial');
        if (! $pendingEmail || $pendingEmail !== $user->email) {
            $this->clearInitialChallenge($request, $emailOtp, $user);

            return redirect()->route('login')->withErrors([
                'login' => 'The email verification code expired. Sign in again to request a new code.',
            ]);
        }

        if (! $emailOtp->verify($user, $user->email, 'initial', $data['code'])) {
            $this->rejectCode($request, $emailOtp, $user, 'initial');
        }

        if (! $user->markEmailAsVerified()) {
            throw ValidationException::withMessages([
                'code' => 'The registered email address could not be verified. Please try again.',
            ]);
        }

        event(new Verified($user));
        $auditLogger->record('account.email_verified', $user, ['method' => 'otp']);
        $remember = (bool) $request->session()->pull('hris.email_verification.remember', false);
        $this->clearInitialChallenge($request, $emailOtp, $user);
        $mfaChallenge->begin($user, $request, 'code', $remember);

        return redirect()->route('mfa.verify')->with('status', 'Your email is verified. Complete the sign-in verification code to continue.');
    }

    public function resendInitial(Request $request, EmailVerificationOtpService $emailOtp): RedirectResponse
    {
        $user = $this->pendingAdmin($request, $emailOtp);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'login' => 'The email verification request expired. Sign in again to request a new code.',
            ]);
        }

        $emailOtp->send($user, $user->email, 'initial', 'code');
        $request->session()->put('hris.email_verification.attempts', 0);

        return back()->with('status', 'A new email verification code was sent to your registered email address.');
    }

    public function verifyEmailChange(
        Request $request,
        EmailVerificationOtpService $emailOtp,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:6'],
        ]);
        $user = $request->user();
        $pendingEmail = $emailOtp->pendingEmail($user, 'change');

        if (! $pendingEmail) {
            throw ValidationException::withMessages([
                'code' => 'There is no active email change code. Submit the new email address again to request one.',
            ]);
        }

        if (! $emailOtp->verify($user, $pendingEmail, 'change', $data['code'])) {
            $this->rejectCode($request, $emailOtp, $user, 'change');
        }

        DB::transaction(function () use ($user, $pendingEmail): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $emailInUse = User::query()
                ->where('email', $pendingEmail)
                ->where('id', '<>', $lockedUser->id)
                ->exists();

            if ($emailInUse) {
                throw ValidationException::withMessages([
                    'email' => 'That email address is now associated with another account. Submit a different address.',
                ]);
            }

            $lockedUser->forceFill([
                'email' => $pendingEmail,
                'email_verified_at' => now(),
            ])->saveOrFail();
        });

        $request->session()->forget('hris.email_change.attempts');
        $user->refresh();
        event(new Verified($user));
        $auditLogger->record('account.email_changed', $user);

        return redirect()->route('account.profile.edit')
            ->with('success', 'Your new email address has been verified and saved.');
    }

    public function resendEmailChange(Request $request, EmailVerificationOtpService $emailOtp): RedirectResponse
    {
        $user = $request->user();
        $pendingEmail = $emailOtp->pendingEmail($user, 'change');

        if (! $pendingEmail) {
            throw ValidationException::withMessages([
                'email' => 'There is no pending email address. Enter a new address to start verification.',
            ]);
        }

        $emailOtp->send($user, $pendingEmail, 'change', 'code');
        $request->session()->put('hris.email_change.attempts', 0);

        return back()->with('status', 'A new verification code was sent to the pending email address.');
    }

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

    private function pendingAdmin(Request $request, EmailVerificationOtpService $emailOtp): ?User
    {
        $userId = $request->session()->get('hris.email_verification.pending_user_id');
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user || ! $user->isAdministrator() || ! $user->is_active
            || ! in_array($user->role, User::VALID_ROLES, true)
            || ! filled($user->email) || $user->hasVerifiedEmail()) {
            if ($user) {
                $emailOtp->forget($user, 'initial');
            }
            $request->session()->forget([
                'hris.email_verification.pending_user_id',
                'hris.email_verification.attempts',
                'hris.email_verification.remember',
            ]);

            return null;
        }

        return $user;
    }

    private function clearInitialChallenge(
        Request $request,
        EmailVerificationOtpService $emailOtp,
        User $user,
    ): void {
        $emailOtp->forget($user, 'initial');
        $request->session()->forget([
            'hris.email_verification.pending_user_id',
            'hris.email_verification.attempts',
            'hris.email_verification.remember',
        ]);
    }

    private function rejectCode(
        Request $request,
        EmailVerificationOtpService $emailOtp,
        User $user,
        string $purpose,
    ): never {
        $attemptKey = $purpose === 'initial'
            ? 'hris.email_verification.attempts'
            : 'hris.email_change.attempts';
        $attempts = (int) $request->session()->get($attemptKey, 0) + 1;
        $request->session()->put($attemptKey, $attempts);

        if ($attempts >= 5) {
            $emailOtp->forget($user, $purpose);
            $request->session()->forget($attemptKey);
            if ($purpose === 'initial') {
                $request->session()->forget([
                    'hris.email_verification.pending_user_id',
                    'hris.email_verification.remember',
                ]);
            }

            throw ValidationException::withMessages([
                'code' => 'Too many incorrect verification codes. Request a new code and try again.',
            ]);
        }

        throw ValidationException::withMessages([
            'code' => 'The email verification code is invalid or expired.',
        ]);
    }
}
