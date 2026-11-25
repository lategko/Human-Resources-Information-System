<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailVerificationOtp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailVerificationOtpService
{
    public function resendCooldownSeconds(User $user, string $purpose): int
    {
        return RateLimiter::availableIn("hris:email-otp:send:{$purpose}:{$user->id}");
    }

    public function send(User $user, string $email, string $purpose, string $errorField): void
    {
        $email = Str::lower(trim($email));
        $rateLimitKey = "hris:email-otp:send:{$purpose}:{$user->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw ValidationException::withMessages([
                $errorField => 'Too many verification code requests. Wait one minute before trying again.',
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);
        $code = (string) random_int(100000, 999999);
        $cacheKey = $this->cacheKey($user, $purpose);
        Cache::put($cacheKey, [
            'email' => $email,
            'hash' => Hash::make($code),
        ], now()->addMinutes(10));

        try {
            $recipient = Notification::route('mail', $email);
            $notification = new EmailVerificationOtp($code);

            if (app()->environment('local')) {
                $recipient->notifyNow($notification);
            } else {
                $recipient->notify($notification);
            }
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            Log::warning('Email verification OTP could not be dispatched.', [
                'user_id' => $user->id,
                'purpose' => $purpose,
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                $errorField => 'The email verification code could not be sent. Please try again later.',
            ]);
        }
    }

    public function verify(User $user, string $email, string $purpose, string $code): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $cacheKey = $this->cacheKey($user, $purpose);

        return Cache::lock($cacheKey.':verify-lock', 10)->get(function () use ($cacheKey, $email, $code): bool {
            $challenge = Cache::get($cacheKey);
            if (! $this->matches($challenge, $email) || ! Hash::check($code, $challenge['hash'])) {
                return false;
            }

            Cache::forget($cacheKey);

            return true;
        });
    }

    public function pendingEmail(User $user, string $purpose): ?string
    {
        $challenge = Cache::get($this->cacheKey($user, $purpose));

        return is_array($challenge) && is_string($challenge['email'] ?? null)
            ? $challenge['email']
            : null;
    }

    public function forget(User $user, string $purpose): void
    {
        Cache::forget($this->cacheKey($user, $purpose));
    }

    private function cacheKey(User $user, string $purpose): string
    {
        return "hris:email-otp:{$purpose}:{$user->id}";
    }

    private function matches(mixed $challenge, string $email): bool
    {
        return is_array($challenge)
            && ($challenge['email'] ?? null) === Str::lower(trim($email))
            && is_string($challenge['hash'] ?? null);
    }
}
