<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        $rules = [
            'name' => [$employee ? 'nullable' : 'required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ];

        if ($employee) {
            $rules += [
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'sex' => ['nullable', 'string', 'max:20'],
                'date_of_birth' => ['nullable', 'date', 'before:today'],
                'contact_number' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:2000'],
            ];
        } else {
            $rules += [
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

        $data = $request->validate($rules);
        $employeeFields = [
            'first_name', 'middle_name', 'last_name', 'suffix', 'sex',
            'date_of_birth', 'contact_number', 'address',
        ];

        DB::transaction(function () use ($data, $employee, $employeeFields, $user): void {
            if ($employee) {
                $employee->update(Arr::only($data, $employeeFields));
                $user->name = $employee->full_name;
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

            $user->email = $data['email'];
            $user->save();
        });

        $auditLogger->record('account.profile_updated', $user, ['fields' => array_keys($data)]);

        return redirect()->route('account.profile')->with('success', 'Your profile information has been updated.');
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
            'new_password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();
        $user->password = $data['new_password'];
        $user->save();
        $auditLogger->record('account.password_updated', $user);

        return redirect()->route('account.profile')->with('success', 'Your password has been changed.');
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
