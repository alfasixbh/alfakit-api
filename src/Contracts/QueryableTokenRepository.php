<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A token repository the "API tokens" page can list: its query returns Eloquent models
 * implementing `AccessToken`, with `name`, `last_used_at`, `expires_at` and `created_at`
 * columns. Repositories without it need their own tokens page (`tokensPage()`).
 */
interface QueryableTokenRepository extends TokenRepository
{
    /**
     * `$user`'s tokens issued on `$panelId` (and for `$tenant` on tenancy panels).
     *
     * @return Builder<covariant Model>
     */
    public function tokensQuery(Authenticatable $user, string $panelId, ?Model $tenant = null): Builder;
}
