<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\SessionSecurityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class AccountProfileController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('account.profile', [
            'user' => $request->user()->load('employee.department', 'employee.position'),
        ]);
    }

    public function edit(Request $request): View
    {
        return view('account.profile-edit', [
            'user' => $request->user()->load('employee.department', 'employee.position'),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user()->loadMissing('employee');
        $employee = $user->employee;
        $request->merge([
            'username' => filled($request->input('username'))
                ? Str::lower(trim((string) $request->input('username')))
                : null,
            'email' => filled($request->input('email'))
                ? Str::lower(trim((string) $request->input('email')))
                : null,
        ]);
        $rules = [
            'username' => [
                Rule::requiredIf(fn () => $employee || ! filled($request->input('email'))),
                'nullable', 'string', 'min:3', 'max:100',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ];

        if ($employee) {
            $rules += [
                'contact_number' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:2000'],
            ];
        } else {
            $rules += [
                'name' => ['required', 'string', 'max:150'],
                'first_name' => ['nullable', 'required_with:last_name', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['nullable', 'required_with:first_name', 'string', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'profile_gender' => ['nullable', 'string', 'max:20'],
                'profile_birthdate' => ['nullable', 'date', 'before:today'],
                'profile_contact_number' => ['nullable', 'string', 'max:40'],
                'profile_address' => ['nullable', 'string', 'max:2000'],
            ];
        }
        $credentialsChanged = $user->username !== $request->input('username')
            || $user->email !== $request->input('email');
        if ($credentialsChanged || $request->filled('new_password')) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        if ($request->filled('new_password')) {
            $rules += [
                'new_password' => ['required', 'string', Password::min(12), 'confirmed'],
            ];
        }

        $data = $request->validate($rules);
        $emailChanged = $user->email !== ($data['email'] ?? null);

        DB::transaction(function () use ($data, $emailChanged, $employee, $user): void {
            if ($employee) {
                $employee->update(Arr::only($data, ['contact_number', 'address']));
            } else {
                $nameParts = Arr::only($data, ['first_name', 'middle_name', 'last_name', 'suffix']);
                $hasNameParts = filled($nameParts['first_name'] ?? null) && filled($nameParts['last_name'] ?? null);
                $user->name = $hasNameParts
                    ? trim(implode(' ', array_filter($nameParts)))
                    : $data['name'];
                foreach ($nameParts as $field => $value) {
                    if (array_key_exists($field, $data)) {
                        $user->{$field} = $value;
                    }
                }
                foreach (['profile_gender', 'profile_birthdate', 'profile_contact_number', 'profile_address'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $user->{$field} = $data[$field];
                    }
                }
            }

            $user->username = $data['username'] ?? null;
            if ($emailChanged) {
                $user->email = $data['email'] ?? null;
                $user->email_verified_at = null;
            }
            if (filled($data['new_password'] ?? null)) {
                $user->password = $data['new_password'];
            }
            $user->save();
        });
        if (filled($data['new_password'] ?? null)) {
            app(SessionSecurityService::class)->invalidateOtherSessions(
                $user,
                $request->session()->getId()
            );
        }
        if ($emailChanged && filled($user->email)) {
            $user->sendEmailVerificationNotification();
        }

        $auditLogger->record('account.profile_updated', $user, ['fields' => array_keys($data)]);

        return redirect()->route('account.profile')->with(
            'success',
            $emailChanged && filled($user->email)
                ? 'Your profile has been updated. Check your email to verify the new address.'
                : 'Your profile information has been updated.'
        );
    }

    public function updatePhoto(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $newPath = $request->file('photo')->storePublicly('profile-photos', 'public');

        if (! $newPath) {
            throw new RuntimeException('The profile photo could not be saved.');
        }

        $oldPath = $user->profile_photo_path;

        try {
            $user->profile_photo_path = $newPath;
            $user->saveOrFail();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPath);
            throw $exception;
        }

        $this->deleteStoredPhoto($oldPath);
        $auditLogger->record('account.profile_photo_updated', $user);

        return redirect()->route('account.profile')->with('success', 'Your profile photo has been updated.');
    }

    public function deletePhoto(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user();
        $oldPath = $user->profile_photo_path;
        $user->profile_photo_path = null;
        $user->saveOrFail();

        $this->deleteStoredPhoto($oldPath);
        $auditLogger->record('account.profile_photo_removed', $user);

        return redirect()->route('account.profile')->with('success', 'Your profile photo has been removed.');
    }

    public function updatePassword(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'string', Password::min(12), 'confirmed'],
        ]);

        $user = $request->user();
        $user->password = $data['new_password'];
        $user->save();
        app(SessionSecurityService::class)->invalidateOtherSessions(
            $user,
            $request->session()->getId()
        );
        $auditLogger->record('account.password_updated', $user);

        return redirect()->route('account.profile')->with('success', 'Your password has been changed.');
    }

    public function generateMfaRecoveryCodes(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['admin', 'mayor', 'office_head'], true), 403);

        $codes = [];
        $hashedCodes = [];
        for ($index = 0; $index < 10; $index++) {
            $code = 'HRIS-'.strtoupper(Str::random(10));
            $codes[] = $code;
            $hashedCodes[] = Hash::make($code);
        }

        $request->user()->forceFill([
            'mfa_recovery_codes' => $hashedCodes,
            'mfa_recovery_codes_generated_at' => now(),
        ])->save();

        $auditLogger->record('account.mfa_recovery_codes_generated', $request->user());

        return redirect()->route('account.profile')->with(
            'mfa_recovery_codes',
            $codes
        );
    }

    private function deleteStoredPhoto(?string $path): void
    {
        if (! $path || ! Str::startsWith($path, 'profile-photos/')) {
            return;
        }

        $disk = Storage::disk('public');

        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new RuntimeException('The previous profile photo could not be removed.');
        }
    }
}
