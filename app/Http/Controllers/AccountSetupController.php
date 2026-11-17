<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountSetupController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->isAdministrator()) {
            return redirect()->route('dashboard');
        }

        return view('account.setup', [
            'user' => $request->user()->load('employee'),
        ]);
    }

    public function update(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $user = $request->user()->loadMissing('employee');
        if ($user->isAdministrator()) {
            return redirect()->route('dashboard');
        }

        $request->merge([
            'username' => Str::lower(trim((string) $request->input('username'))),
            'email' => filled($request->input('email'))
                ? Str::lower(trim((string) $request->input('email')))
                : null,
        ]);

        $rules = [
            'username' => [
                'required', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:2000'],
        ];
        if ($user->must_change_password) {
            $rules += [
                'current_password' => ['required', 'current_password'],
                'new_password' => ['required', 'string', Password::min(12), 'confirmed'],
            ];
        }

        $data = $request->validate($rules);
        $emailChanged = $user->email !== ($data['email'] ?? null);

        DB::transaction(function () use ($data, $emailChanged, $user): void {
            if ($user->employee) {
                $user->employee->update([
                    'contact_number' => $data['contact_number'] ?? null,
                    'address' => $data['address'] ?? null,
                ]);
            } else {
                $user->profile_contact_number = $data['contact_number'] ?? null;
                $user->profile_address = $data['address'] ?? null;
            }

            $user->username = $data['username'];
            if ($emailChanged) {
                $user->email = $data['email'] ?? null;
                $user->email_verified_at = null;
            }
            if ($user->must_change_password) {
                $user->password = $data['new_password'];
                $user->must_change_password = false;
            }
            $user->account_setup_completed = true;
            $user->save();
        });

        if (filled($user->email) && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
        $auditLogger->record('account.setup_completed', $user, [
            'email_added' => $emailChanged && filled($user->email),
            'username_changed' => $user->wasChanged('username'),
        ]);

        return redirect()->route('dashboard')->with(
            'success',
            $user->hasVerifiedEmail()
                ? 'Your account setup is complete.'
                : 'Your account setup is complete. Check your email to verify your email address.'
        );
    }
}
