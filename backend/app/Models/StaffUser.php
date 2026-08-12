<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Internal bank employee account — staff or admin, distinguished by `role`. Deliberately not the
 * customer-facing `User` model: no phone/Telegram/OTP concept applies here, and Sanctum's
 * personal_access_tokens table is polymorphic (tokenable_type/id), so this coexists on the same
 * `auth:sanctum` guard as User without any config changes — see EnsureStaffUser for how routes
 * tell the two apart.
 */
class StaffUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
