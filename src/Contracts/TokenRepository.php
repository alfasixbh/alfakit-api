<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Contracts;

use AlfaSix\AlfakitApi\Tokens\NewAccessToken;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Issues, finds and revokes personal access tokens. The default `DatabaseTokenRepository`
 * stores SHA-256 hashes in `alfakit_api_tokens`; bind your own implementation with
 * `ApiPlugin::make()->tokenRepository(...)` (for example one issuing Sanctum tokens).
 */
interface TokenRepository
{
    /**
     * @param  list<string>  $abilities
     */
    public function create(Authenticatable $user, string $panelId, string $name, array $abilities, ?DateTimeInterface $expiresAt = null, ?Model $tenant = null): NewAccessToken;

    /**
     * The token for a plain-text bearer token issued on `$panelId`, or null when it is
     * unknown, malformed or was issued on another panel. Expiry is checked by the caller.
     */
    public function find(string $plainTextToken, string $panelId): ?AccessToken;

    /**
     * Record that the token was just used (implementations may throttle the writes).
     */
    public function touch(AccessToken $token, ?string $ip = null): void;

    /**
     * Revoke one of `$user`'s tokens; false when the user has no such token.
     */
    public function revoke(Authenticatable $user, string $tokenId): bool;
}
