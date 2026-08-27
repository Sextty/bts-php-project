<?php

namespace App\Models;

use App\Auth\PermissionRegistry;
use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Internal bank employee account — staff, security or admin, distinguished by `role`.
 */
class StaffUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /** Role hierarchy ranks — higher outranks lower for the role gate (isAtLeast). */
    private const ROLE_RANKS = [
        Role::Staff->value => 10,
        Role::CreditOfficer->value => 10,
        Role::SeniorStaff->value => 20,
        Role::BranchManager->value => 30,
        Role::Security->value => 35,
        Role::Admin->value => 40,
        Role::SuperAdmin->value => 50,
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'status',
        'branch_id',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function role(): Role
    {
        return Role::tryFrom($this->role) ?? Role::Staff;
    }

    public function isAdmin(): bool
    {
        return $this->isSuperuser();
    }

    public function isSecurity(): bool
    {
        return $this->role === Role::Security->value;
    }

    /** Admin and super_admin hold every permission; everyone else is whatever the registry says. */
    public function isSuperuser(): bool
    {
        return in_array($this->role, [Role::Admin->value, Role::SuperAdmin->value], true);
    }

    /** Operational staff are always branch-scoped. Missing assignment denies access by default. */
    public function isBranchRestricted(): bool
    {
        return ! $this->isSuperuser() && ! $this->isSecurity();
    }

    public function hasPermission(Permission|string $permission): bool
    {
        $permission = $permission instanceof Permission ? $permission : Permission::tryFrom($permission);

        if ($permission === null) {
            return false;
        }

        return $this->isSuperuser() || PermissionRegistry::has($this->role, $permission);
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
     * Whether this staff member is senior enough for a role-gated area.
     */
    public function isAtLeast(string $role): bool
    {
        return ($this->role() === Role::tryFrom($role))
            || (self::ROLE_RANKS[$this->role] ?? -1) >= (self::ROLE_RANKS[$role] ?? PHP_INT_MAX);
    }

    /**
     * Branch isolation: superusers and security have global duties; operational staff require
     * an explicit branch and may only handle applications routed to that branch.
     */
    public function canAccessApplication(CreditApplication $application): bool
    {
        if (! $this->isBranchRestricted()) {
            return true;
        }

        return $this->branch_id !== null
            && $application->branch_id !== null
            && $application->branch_id === $this->branch_id;
    }
}
