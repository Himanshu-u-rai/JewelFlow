<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'mobile_number',
        'email',
        'password',
        'role_id',
        // shop_id is set server-side only (StaffController uses the owner's
        // shop_id; registration leaves it null until shop creation). It was
        // missing here, so StaffController's create() silently dropped it and
        // staff were created with NULL shop_id → treated as new users on login
        // and bounced to subscription/plans. Both create() sites pass fixed
        // arrays (never $request->all()), so this is not a mass-assignment risk.
        'shop_id',
        'is_active',
        'first_name',
        'last_name',
        // Which customer-facing product this account belongs to: 'erp' | 'dhiran'.
        // One account = one product (no combined multi-product account). Defaults
        // to 'erp' (see the booted() creating hook) so all existing behaviour is
        // unchanged. Set server-side at registration based on the request realm.
        'realm',
    ];

    public const REALM_ERP = 'erp';
    public const REALM_DHIRAN = 'dhiran';
    public const REALMS = [self::REALM_ERP, self::REALM_DHIRAN];

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
            'email_verified_at'    => 'datetime',
            'email_otp_expires_at' => 'datetime',
            'password'             => 'hashed',
            'is_active'            => 'boolean',
        ];
    }

    /**
     * Default the realm to 'erp' on create when not explicitly set, so any code
     * path that creates a user without specifying a realm keeps today's ERP
     * behaviour. Dhiran registration sets realm='dhiran' explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->realm)) {
                $user->realm = self::REALM_ERP;
            }
        });
    }

    /** Scope to a single realm: User::realm('dhiran')->... */
    public function scopeRealm($query, string $realm)
    {
        return $query->where('realm', $realm);
    }

    /** This account belongs to the Dhiran product. */
    public function isDhiran(): bool
    {
        return ($this->realm ?? self::REALM_ERP) === self::REALM_DHIRAN;
    }

    /** This account belongs to the Retail ERP product (the default). */
    public function isErp(): bool
    {
        return ($this->realm ?? self::REALM_ERP) === self::REALM_ERP;
    }

    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function username()
    {
        return 'mobile_number';
    }

    /**
     * Get the shop this user belongs to.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the role of this user.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->role) {
            return false;
        }

        return $this->role->hasPermission($permission);
    }

    /**
     * Check if user has any of the given permissions.
     */
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
     * Check if user has all of the given permissions.
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $roleName): bool
    {
        return $this->role && $this->role->name === $roleName;
    }

    /**
     * Helper to determine if user is owner.
     */
    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    /**
     * Helper to determine if user is manager.
     */
    public function isManager(): bool
    {
        return $this->hasRole('manager');
    }

    /**
     * Helper to determine if user is staff.
     */
    public function isStaff(): bool
    {
        return $this->hasRole('staff');
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function scopeInactive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS FALSE');
    }

}
