<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'can_final_approve',
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
            'is_active' => 'boolean',
            'can_final_approve' => 'boolean',
        ];
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function isAdministrator(): bool
    {
        return $this->role === 'admin';
    }

    public function isOfficeHead(): bool
    {
        return $this->role === 'office_head';
    }

    public function isHrmo(): bool
    {
        return $this->role === 'hrmo';
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
        return $this->isAdministrator() && $this->can_final_approve;
    }
}
