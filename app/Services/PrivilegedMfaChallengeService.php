<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PrivilegedLoginOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrivilegedMfaChallengeService
{
    public function begin(User $user, Request $request, string $errorField, ?bool $remember = null): void
    {
        $code = (string) random_int(100000, 999999);
        Cache::put('hris:mfa:otp:'.$user->id, Hash::make($code), now()->addMinutes(10));
        $request->session()->put('hris.mfa.pending_user_id', $user->id);
        $request->session()->put('hris.mfa.attempts', 0);
        $request->session()->put('hris.mfa.remember', $remember ?? $request->boolean('remember'));
        $request->session()->save();

        try {
            $notification = new PrivilegedLoginOtp($code);
            if (app()->environment('local')) {
                Notification::sendNow($user, $notification);
            } else {
                $user->notify($notification);
            }
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
                $errorField => 'The verification message could not be sent. Please try again later.',
            ]);
        }
    }
}
