<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Contracts;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A personal access token, as the API middleware sees it. `DatabaseTokenRepository`
 * returns `ApiToken` models; your own repository (e.g. one backed by Laravel Sanctum)
 * returns its own implementation.
 */
interface AccessToken
{
    public function getTokenId(): string;

    public function getTokenName(): string;

    /** The panel the token was issued on; it works on that panel's API only. */
    public function getPanelId(): string;

    public function getUser(): ?Authenticatable;

    /**
     * The tenant the token is bound to (tokens created on tenancy panels), or null.
     */
    public function getTenant(): ?Model;

    /**
     * @return list<string>
     */
    public function getAbilities(): array;

    /**
     * Whether the token grants `$ability` (`posts:read`), directly or through a wildcard
     * (`*`, `posts:*`).
     */
    public function can(string $ability): bool;

    public function getExpiresAt(): ?DateTimeInterface;

    public function getLastUsedAt(): ?DateTimeInterface;

    public function isExpired(): bool;
}
