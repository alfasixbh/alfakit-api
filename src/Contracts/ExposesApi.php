<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Contracts;

use AlfaSix\AlfakitApi\ResourceApi;

/**
 * A resource that configures (and opts into) its own API. Resources implementing it are
 * exposed on every panel with the API plugin, without being listed in `resources()`.
 */
interface ExposesApi
{
    public static function api(ResourceApi $api): ResourceApi;
}
