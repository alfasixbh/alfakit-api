<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tokens;

use AlfaSix\AlfakitApi\Contracts\AccessToken;
use SensitiveParameter;

/**
 * A freshly issued token and its plain-text value, which is shown to the user once and
 * never stored.
 */
final class NewAccessToken
{
    public function __construct(
        public readonly AccessToken $accessToken,
        #[SensitiveParameter]
        public readonly string $plainTextToken,
    ) {}
}
