<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tokens;

use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Contracts\QueryableTokenRepository;
use AlfaSix\AlfakitApi\Models\SanctumApiToken;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Issues API tokens as Laravel Sanctum personal access tokens (`personal_access_tokens`),
 * so the same tokens work with `auth:sanctum` routes: `ApiPlugin::make()->tokenRepository(
 * new SanctumTokenRepository)`. The panel and tenant are stored as marker abilities.
 *
 * With `panelBound: false`, any Sanctum token of the user works on the API too (also the
 * ones your app creates with `$user->createToken(...)`): give those `posts:read`-style
 * abilities, or `*`.
 */
class SanctumTokenRepository implements QueryableTokenRepository
{
    /** Seconds between two `last_used_at` writes of the same token. */
    public const TOUCH_INTERVAL = 60;

    /**
     * @param  class-string<SanctumApiToken>  $model
     */
    public function __construct(
        protected string $model = SanctumApiToken::class,
        protected bool $panelBound = true,
    ) {
        if (! class_exists('Laravel\\Sanctum\\PersonalAccessToken')) {
            throw new LogicException('SanctumTokenRepository needs laravel/sanctum: composer require laravel/sanctum.');
        }
    }

    public function create(Authenticatable $user, string $panelId, string $name, array $abilities, ?DateTimeInterface $expiresAt = null, ?Model $tenant = null): NewAccessToken
    {
        if (! $user instanceof Model) {
            throw new InvalidArgumentException('API tokens belong to Eloquent users.');
        }

        // Sanctum's own token format: an optional prefix, 40 random characters and a CRC32
        // checksum (so secret scanners recognise leaked tokens).
        $random = Str::random(40);
        $secret = config('sanctum.token_prefix', '').$random.hash('crc32b', $random);

        /** @var SanctumApiToken $token */
        $token = new $this->model;
        $token->forceFill([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'name' => mb_substr(trim($name), 0, 255),
            'token' => hash('sha256', $secret),
            'abilities' => [
                ...Abilities::normalize($abilities),
                SanctumApiToken::PANEL_MARKER.$panelId,
                ...($tenant !== null ? [SanctumApiToken::tenantMarker($tenant)] : []),
            ],
            'expires_at' => $expiresAt,
        ])->save();

        return new NewAccessToken($token, $token->getKey().'|'.$secret);
    }

    public function find(#[SensitiveParameter] string $plainTextToken, string $panelId): ?AccessToken
    {
        if ($plainTextToken === '') {
            return null;
        }

        $token = $this->model::findToken($plainTextToken);

        if (! $token instanceof SanctumApiToken) {
            return null;
        }

        $tokenPanel = $token->getPanelId();

        if ($tokenPanel === '') {
            return $this->panelBound ? null : $token;
        }

        return hash_equals($tokenPanel, $panelId) ? $token : null;
    }

    public function touch(AccessToken $token, ?string $ip = null): void
    {
        if (! $token instanceof SanctumApiToken) {
            return;
        }

        $lastUsedAt = $token->getLastUsedAt();

        if ($lastUsedAt !== null && time() - $lastUsedAt->getTimestamp() < self::TOUCH_INTERVAL) {
            return;
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function revoke(Authenticatable $user, string $tokenId): bool
    {
        if (! $user instanceof Model || ! ctype_digit($tokenId)) {
            return false;
        }

        return $this->model::query()
            ->whereKey((int) $tokenId)
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->delete() > 0;
    }

    public function tokensQuery(Authenticatable $user, string $panelId, ?Model $tenant = null): Builder
    {
        if (! $user instanceof Model) {
            return $this->model::query()->whereRaw('1 = 0');
        }

        $query = $this->model::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->whereJsonContains('abilities', SanctumApiToken::PANEL_MARKER.$panelId);

        if ($tenant !== null) {
            $query->whereJsonContains('abilities', SanctumApiToken::tenantMarker($tenant));
        }

        return $query;
    }
}
