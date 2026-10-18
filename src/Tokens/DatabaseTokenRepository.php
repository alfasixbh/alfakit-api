<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tokens;

use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Contracts\QueryableTokenRepository;
use AlfaSix\AlfakitApi\Models\ApiToken;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Stores tokens in `alfakit_api_tokens`. A token reads `{id}|{40 random characters}`
 * (the format Laravel Sanctum uses); only the SHA-256 hash of the secret is stored and
 * compared in constant time.
 */
class DatabaseTokenRepository implements QueryableTokenRepository
{
    /** Seconds between two `last_used_at` writes of the same token. */
    public const TOUCH_INTERVAL = 60;

    /**
     * @param  class-string<ApiToken>  $model
     */
    public function __construct(protected string $model = ApiToken::class) {}

    public function create(Authenticatable $user, string $panelId, string $name, array $abilities, ?DateTimeInterface $expiresAt = null, ?Model $tenant = null): NewAccessToken
    {
        if (! $user instanceof Model) {
            throw new InvalidArgumentException('API tokens belong to Eloquent users.');
        }

        $secret = Str::random(40);

        /** @var ApiToken $token */
        $token = new $this->model;
        $token->forceFill([
            'panel' => $panelId,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'tenant_type' => $tenant?->getMorphClass(),
            'tenant_id' => $tenant?->getKey(),
            'name' => mb_substr(trim($name), 0, 255),
            'token' => hash('sha256', $secret),
            'abilities' => Abilities::normalize($abilities),
            'expires_at' => $expiresAt,
        ])->save();

        return new NewAccessToken($token, $token->getKey().'|'.$secret);
    }

    public function find(#[SensitiveParameter] string $plainTextToken, string $panelId): ?AccessToken
    {
        if (! str_contains($plainTextToken, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plainTextToken, 2);

        if ($id === '' || ! ctype_digit($id) || $secret === '') {
            return null;
        }

        $token = $this->model::query()->whereKey((int) $id)->first();

        if (! $token instanceof ApiToken || ! hash_equals((string) $token->getAttribute('token'), hash('sha256', $secret))) {
            return null;
        }

        return $token->getPanelId() === $panelId ? $token : null;
    }

    public function touch(AccessToken $token, ?string $ip = null): void
    {
        if (! $token instanceof ApiToken) {
            return;
        }

        $lastUsedAt = $token->getLastUsedAt();

        if ($lastUsedAt !== null && time() - $lastUsedAt->getTimestamp() < self::TOUCH_INTERVAL && $token->getAttribute('last_used_ip') === $ip) {
            return;
        }

        $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
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

        return $this->model::query()->ownedBy($user, $panelId, $tenant);
    }
}
