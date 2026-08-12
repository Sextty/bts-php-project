<?php

namespace App\Models;

use Database\Factories\UserFactory;
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
        'telegram_chat_id',
        'telegram_link_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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
     * Points the reset link at the Next.js frontend's page instead of Laravel's default
     * (a `password.reset` named route, which doesn't exist in this API-only app).
     */
    public function sendPasswordResetNotification($token): void
    {
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
