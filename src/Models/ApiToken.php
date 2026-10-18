<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Models;

use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Tokens\Abilities;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A personal access token of the API plugin. Only the SHA-256 hash of the secret is
 * stored (`token`, hidden from serialisation).
 *
 * @property int $id
 * @property string $panel
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $expires_at
 */
class ApiToken extends Model implements AccessToken
{
    protected $table = 'alfakit_api_tokens';

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function tenant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Tokens of `$user` on `$panelId` (and of `$tenant` on tenancy panels).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, Model $user, string $panelId, ?Model $tenant = null): Builder
    {
        $query->where('panel', $panelId)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey());

        if ($tenant !== null) {
            $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', $tenant->getKey());
        }

        return $query;
    }

    public function getTokenId(): string
    {
        return (string) $this->getKey();
    }

    public function getTokenName(): string
    {
        return (string) $this->getAttribute('name');
    }

    public function getPanelId(): string
    {
        return (string) $this->getAttribute('panel');
    }

    public function getUser(): ?Authenticatable
    {
        $user = $this->tokenable;

        return $user instanceof Authenticatable ? $user : null;
    }

    public function getTenant(): ?Model
    {
        if ($this->getAttribute('tenant_id') === null) {
            return null;
        }

        $tenant = $this->tenant;

        return $tenant instanceof Model ? $tenant : null;
    }

    public function isTenantBound(): bool
    {
        return $this->getAttribute('tenant_id') !== null;
    }

    public function getAbilities(): array
    {
        $abilities = $this->getAttribute('abilities');

        return is_array($abilities) ? Abilities::normalize($abilities) : [];
    }

    public function can(string $ability): bool
    {
        return Abilities::allows($this->getAbilities(), $ability);
    }

    public function getExpiresAt(): ?DateTimeInterface
    {
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt instanceof DateTimeInterface ? $expiresAt : null;
    }

    public function getLastUsedAt(): ?DateTimeInterface
    {
        $lastUsedAt = $this->getAttribute('last_used_at');

        return $lastUsedAt instanceof DateTimeInterface ? $lastUsedAt : null;
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();

        return $expiresAt !== null && $expiresAt->getTimestamp() <= time();
    }
}
