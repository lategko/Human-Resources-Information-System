<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    protected $attributes = [
        'must_change_password' => false,
        'account_setup_completed' => true,
    ];

    public const VALID_ROLES = [
        'admin',
        'mayor',
        'office_head',
        'employee',
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'must_change_password',
        'account_setup_completed',
        'role',
        'is_active',
        'can_final_approve',
        'profile_photo_path',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'profile_gender',
        'profile_birthdate',
        'profile_contact_number',
        'profile_address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'account_setup_completed' => 'boolean',
            'is_active' => 'boolean',
            'can_final_approve' => 'boolean',
            'profile_birthdate' => 'date',
        ];
    }

    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = filled($value) ? mb_strtolower(trim($value)) : null;
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = filled($value) ? mb_strtolower(trim($value)) : null;
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function departmentHead(): HasOne
    {
        return $this->hasOne(Department::class, 'head_user_id');
    }

    public function officeHeadAvailabilityRecords(): HasMany
    {
        return $this->hasMany(OfficeHeadAvailability::class, 'office_head_user_id');
    }

    public function isAdministrator(): bool
    {
        return $this->role === 'admin';
    }

    public function isMayor(): bool
    {
        return $this->role === 'mayor';
    }

    public function isOfficeHead(): bool
    {
        return $this->role === 'office_head';
    }

    public function isHrmo(): bool
    {
        return $this->isAdministrator();
    }

    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function canManageHr(): bool
    {
        return $this->isAdministrator() || $this->isHrmo();
    }

    public function canFinalApprove(): bool
    {
        return $this->isMayor();
    }

    public function canApproveMayorLeave(): bool
    {
        return $this->is_active && $this->isAdministrator() && $this->can_final_approve;
    }
}
