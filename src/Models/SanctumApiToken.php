<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Models;

use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Tokens\Abilities;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Laravel Sanctum personal access token (`personal_access_tokens`) seen as an API plugin
 * token. Sanctum has no panel or tenant columns, so `SanctumTokenRepository` records them
 * as marker abilities (`alfakit-panel:{id}`, `alfakit-tenant:{type}:{key}`), which
 * `getAbilities()` leaves out.
 */
class SanctumApiToken extends PersonalAccessToken implements AccessToken
{
    public const PANEL_MARKER = 'alfakit-panel:';

    public const TENANT_MARKER = 'alfakit-tenant:';

    /** Sanctum's table (the subclass would otherwise be `sanctum_api_tokens`). */
    protected $table = 'personal_access_tokens';

    /**
     * @return list<string>
     */
    public function getRawAbilities(): array
    {
        $abilities = $this->getAttribute('abilities');

        return is_array($abilities) ? array_values(array_filter($abilities, 'is_string')) : [];
    }

    public function getTokenId(): string
    {
        return (string) $this->getKey();
    }

    public function getTokenName(): string
    {
        return (string) $this->getAttribute('name');
    }

    /**
     * The panel marker, or an empty string for tokens created outside the API plugin.
     */
    public function getPanelId(): string
    {
        foreach ($this->getRawAbilities() as $ability) {
            if (str_starts_with($ability, self::PANEL_MARKER)) {
                return substr($ability, strlen(self::PANEL_MARKER));
            }
        }

        return '';
    }

    public function getUser(): ?Authenticatable
    {
        $user = $this->getAttribute('tokenable');

        return $user instanceof Authenticatable ? $user : null;
    }

    public function getTenantMarker(): ?string
    {
        foreach ($this->getRawAbilities() as $ability) {
            if (str_starts_with($ability, self::TENANT_MARKER)) {
                return $ability;
            }
        }

        return null;
    }

    public function getTenant(): ?Model
    {
        $marker = $this->getTenantMarker();

        if ($marker === null) {
            return null;
        }

        $value = substr($marker, strlen(self::TENANT_MARKER));
        $separator = strrpos($value, ':');

        if ($separator === false) {
            return null;
        }

        $type = substr($value, 0, $separator);
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return null;
        }

        return $class::query()->find(substr($value, $separator + 1));
    }

    public static function tenantMarker(Model $tenant): string
    {
        return self::TENANT_MARKER.$tenant->getMorphClass().':'.$tenant->getKey();
    }

    public function getAbilities(): array
    {
        return Abilities::normalize(array_values(array_filter(
            $this->getRawAbilities(),
            static fn (string $ability): bool => ! str_starts_with($ability, self::PANEL_MARKER) && ! str_starts_with($ability, self::TENANT_MARKER),
        )));
    }

    /**
     * API abilities (`posts:read`, `posts:*`, `*`), wildcards included. Sanctum's guard
     * uses its own model, so `$user->tokenCan()` keeps Sanctum's rules.
     *
     * @param  mixed  $ability
     */
    public function can($ability): bool
    {
        return is_string($ability) && Abilities::allows($this->getAbilities(), $ability);
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

    /**
     * `expires_at`, and `sanctum.expiration` (minutes after creation) like Sanctum's guard.
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();

        if ($expiresAt !== null && $expiresAt->getTimestamp() <= now()->getTimestamp()) {
            return true;
        }

        $lifetime = config('sanctum.expiration');
        $createdAt = $this->getAttribute('created_at');

        return is_numeric($lifetime) && $createdAt instanceof DateTimeInterface && $createdAt->getTimestamp() + (int) $lifetime * 60 <= now()->getTimestamp();
    }
}
