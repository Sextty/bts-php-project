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
 * Internal bank employee account — staff or admin, distinguished by `role`. Deliberately not the
 * customer-facing `User` model: no phone/Telegram/OTP concept applies here, and Sanctum's
 * personal_access_tokens table is polymorphic (tokenable_type/id), so this coexists on the same
 * `auth:sanctum` guard as User without any config changes — see EnsureStaffUser for how routes
 * tell the two apart.
 *
 * Authorization: capabilities come from PermissionRegistry via hasPermission() (role → permission
 * map, so future roles like credit_officer/branch_manager plug in with a registry entry).
 * Scoping comes from canAccessApplication(): a staff member assigned to a branch (branch_id set)
 * only sees applications routed to that branch; unassigned staff are global; superusers (admin,
 * super_admin) are never branch-restricted.
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
        return $this->role === Role::Admin->value;
    }

    /** Admin and super_admin hold every permission; everyone else is whatever the registry says. */
    public function isSuperuser(): bool
    {
        return in_array($this->role, [Role::Admin->value, Role::SuperAdmin->value], true);
    }

    /**
     * A staff member assigned to a branch is scoped to it — unless superuser, whose whole point
     * is to see everything (including every branch).
     */
    public function isBranchRestricted(): bool
    {
        return $this->branch_id !== null && ! $this->isSuperuser();
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
     * Whether this staff member is senior enough for a role-gated area (e.g. the admin portal's
     * final decision). A super_admin passes the admin gate; a branch_manager never will — final
     * approval is bank-level.
     */
    public function isAtLeast(string $role): bool
    {
        return ($this->role() === Role::tryFrom($role))
            || (self::ROLE_RANKS[$this->role] ?? -1) >= (self::ROLE_RANKS[$role] ?? PHP_INT_MAX);
    }

    /**
     * Branch isolation: can this staff member handle this application? Superusers and unassigned
     * staff may handle anything; a branch-assigned staff member only the applications routed to
     * their branch. Applications with no branch yet (submitted while no branch was configured)
     * are visible only to the unrestricted.
     */
    public function canAccessApplication(CreditApplication $application): bool
    {
        if (! $this->isBranchRestricted()) {
            return true;
        }

        return $application->branch_id !== null && $application->branch_id === $this->branch_id;
    }
}
