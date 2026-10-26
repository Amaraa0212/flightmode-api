<?php

namespace App\Models;

use App\Enums\PermissionKey;
use App\Enums\RoleScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'role_id',
        'airline_id', 'agency_id', 'airport_id', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'two_factor_secret'];

    /** @var array<string>|null */
    private ?array $permissionKeysCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function scope(): RoleScope
    {
        return $this->role?->scope ?? RoleScope::Airline;
    }

    /** Google Authenticator is on: login needs a 6-digit code */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isSystemAdmin(): bool
    {
        return $this->scope() === RoleScope::Admin;
    }

    public function isAgencyUser(): bool
    {
        return $this->scope() === RoleScope::Agency;
    }

    public function isAirportUser(): bool
    {
        return $this->scope() === RoleScope::Airport;
    }

    /** @return array<string> */
    public function permissionKeys(): array
    {
        return $this->permissionKeysCache ??= $this->role?->permissions()->pluck('key')->all() ?? [];
    }

    public const PARTNER_TOKEN_ABILITY = 'partner';

    public const PARTNER_TOKEN_NAME = 'partner-api';

    /** Signs the user out everywhere (the agency API token stays), optionally keeping one token */
    public function revokeSessions(?int $exceptTokenId = null): void
    {
        $this->tokens()
            ->where('name', '!=', self::PARTNER_TOKEN_NAME)
            ->when($exceptTokenId, fn ($q) => $q->whereKeyNot($exceptTokenId))
            ->delete();
    }

    /** True when the current request is authenticated with an agency machine-to-machine token (not a login token). */
    public function usesPartnerToken(): bool
    {
        $token = $this->currentAccessToken();

        return $token instanceof PersonalAccessToken
            && in_array(self::PARTNER_TOKEN_ABILITY, (array) $token->abilities, true)
            && ! in_array('*', (array) $token->abilities, true);
    }

    public function hasPermission(PermissionKey|string $key): bool
    {
        if ($this->isSystemAdmin()) {
            return true;
        }

        $key = $key instanceof PermissionKey ? $key->value : $key;

        return in_array($key, $this->permissionKeys(), true);
    }
}
