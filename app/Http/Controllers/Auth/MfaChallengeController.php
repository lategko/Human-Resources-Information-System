<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MfaChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('hris.mfa.pending_user_id')) {
            return redirect()->route('login');
        }

        return view('auth.mfa-challenge');
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
        ]);

        $userId = $request->session()->get('hris.mfa.pending_user_id');
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user || ! $user->is_active || ! in_array($user->role, ['admin', 'mayor', 'office_head'], true)) {
            $this->clearPendingChallenge($request);
            throw ValidationException::withMessages(['code' => 'The MFA challenge has expired. Please sign in again.']);
        }

        $code = trim($data['code']);
        $otpHash = Cache::get('hris:mfa:otp:'.$user->id);
        $otpValid = is_string($otpHash) && preg_match('/^\d{6}$/', $code) === 1 && Hash::check($code, $otpHash);
        if ($otpValid) {
            $consumedOtpHash = Cache::pull('hris:mfa:otp:'.$user->id);
            $otpValid = is_string($consumedOtpHash) && Hash::check($code, $consumedOtpHash);
        }

        $recoveryCodeUsed = false;
        if (! $otpValid) {
            $recoveryCodeUsed = DB::transaction(function () use ($user, $code): bool {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if (! $lockedUser) {
                    return false;
                }

                $recoveryCodes = is_array($lockedUser->mfa_recovery_codes) ? $lockedUser->mfa_recovery_codes : [];
                foreach ($recoveryCodes as $index => $hashedCode) {
                    if (is_string($hashedCode) && Hash::check(strtoupper($code), $hashedCode)) {
                        unset($recoveryCodes[$index]);
                        $lockedUser->forceFill([
                            'mfa_recovery_codes' => array_values($recoveryCodes),
                        ])->save();
                        return true;
                    }
                }
                return false;
            });
        }

        if (! $otpValid && ! $recoveryCodeUsed) {
            $attempts = (int) $request->session()->get('hris.mfa.attempts', 0) + 1;
            $request->session()->put('hris.mfa.attempts', $attempts);
            if ($attempts >= 5) {
                Cache::forget('hris:mfa:otp:'.$user->id);
                $this->clearPendingChallenge($request);
                throw ValidationException::withMessages([
                    'code' => 'Too many verification attempts. Please sign in again.',
                ]);
            }
            throw ValidationException::withMessages(['code' => 'The verification code or recovery code is invalid or expired.']);
        }

        $request->session()->forget('hris.mfa.attempts');
        if ($otpValid) {
            Cache::forget('hris:mfa:otp:'.$user->id);
        }
        $remember = $request->session()->pull('hris.mfa.remember', false);
        $request->session()->forget('hris.mfa.pending_user_id');
        $request->session()->regenerate();

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $auditLogger->record('auth.login');

        return redirect()->route(! $user->isAdministrator()
            && ($user->must_change_password || ! $user->account_setup_completed)
            ? 'account.setup'
            : 'dashboard');
    }

    private function clearPendingChallenge(Request $request): void
    {
        $userId = $request->session()->get('hris.mfa.pending_user_id');
        if ($userId) {
            Cache::forget('hris:mfa:otp:'.$userId);
        }
        $request->session()->forget('hris.mfa.pending_user_id');
        $request->session()->forget('hris.mfa.remember');
        $request->session()->forget('hris.mfa.attempts');
        $request->session()->forget('hris.mfa.attempts');
    }
}
