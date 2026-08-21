<?php

namespace App\Models;

use Database\Factories\UserFactory;
use App\Auth\PermissionRegistry;
use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'google_id',
        'auth_provider',
        'status',
        'banned_at',
        'banned_reason',
        'banned_by_staff_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'banned_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function bannedByStaff()
    {
        return $this->belongsTo(StaffUser::class, 'banned_by_staff_id');
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null || $this->status === 'suspended';
    }

    public function ban(string $reason, ?StaffUser $staff = null): void
    {
        $this->update([
            'status' => 'suspended',
            'banned_at' => now(),
            'banned_reason' => $reason,
            'banned_by_staff_id' => $staff?->id,
        ]);

        // Revoke all existing tokens immediately
        $this->tokens()->delete();
    }

    public function unban(): void
    {
        $this->update([
            'status' => 'active',
            'banned_at' => null,
            'banned_reason' => null,
            'banned_by_staff_id' => null,
        ]);
    }

    public function otpCodes()
    {
        return $this->hasMany(OtpCode::class);
    }

    public function creditApplications()
    {
        return $this->hasMany(CreditApplication::class);
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * The client role: permissions are the registry's client set, and every one of them is
     * scoped to this user's own records by CreditApplicationPolicy — a permission grants the
     * capability, ownership decides whose rows.
     */
    public function role(): Role
    {
        return Role::Client;
    }

    public function hasPermission(Permission|string $permission): bool
    {
        $permission = $permission instanceof Permission ? $permission : Permission::tryFrom($permission);

        if ($permission === null) {
            return false;
        }

        return PermissionRegistry::has(Role::Client->value, $permission);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Points the reset link at the Next.js frontend's page instead of Laravel's default
     * (a `password.reset` named route, which doesn't exist in this API-only app).
     *
     * Synchronous by default (services.password_reset.queue_delivery=false). With the flag
     * on, the email is sent via SendPasswordResetEmailJob on the queue worker instead —
     * same URL, subject and body.
     */
    public function sendPasswordResetNotification($token): void
    {
        if (config('services.password_reset.queue_delivery', false)) {
            \App\Jobs\SendPasswordResetEmailJob::dispatch($this, $token);

            return;
        }

        $url = rtrim(config('app.frontend_url'), '/')."/reset-password?token={$token}&email=".urlencode($this->email);

        $this->notify(new class($token, $url) extends ResetPassword
        {
            public function __construct($token, private readonly string $url)
            {
                parent::__construct($token);
            }

            public function toMail($notifiable): MailMessage
            {
                return (new MailMessage)
                    ->subject('Reset your BTS Bank password')
                    ->line('You requested a password reset.')
                    ->action('Reset Password', $this->url)
                    ->line('This link expires shortly. If you did not request this, no action is needed.');
            }
        });
    }
}
