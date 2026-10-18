<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tokens;

/**
 * Token abilities are `{resource}:{operation}` strings: `posts:read`, `posts:create`,
 * `posts:update`, `posts:delete` and `posts:bulk:{action}`. `*` grants everything and
 * `posts:*` everything on one resource. Abilities only narrow what a token can do: the
 * user's policies are checked on every request as well.
 */
final class Abilities
{
    public const ALL = '*';

    public const READ = 'read';

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const DELETE = 'delete';

    public static function for(string $resource, string $operation): string
    {
        return $resource.':'.$operation;
    }

    public static function bulk(string $resource, string $action): string
    {
        return $resource.':bulk:'.$action;
    }

    /**
     * @param  list<string>  $granted
     */
    public static function allows(array $granted, string $ability): bool
    {
        foreach ($granted as $candidate) {
            if ($candidate === self::ALL || $candidate === $ability) {
                return true;
            }

            // `posts:*` covers `posts:read` and `posts:bulk:publish`.
            if (str_ends_with($candidate, ':*') && str_starts_with($ability, substr($candidate, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep well-formed abilities only, without duplicates.
     *
     * @param  array<mixed>  $abilities
     * @return list<string>
     */
    public static function normalize(array $abilities): array
    {
        $normalized = [];

        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                continue;
            }

            $ability = trim($ability);

            if ($ability === self::ALL || preg_match('/^[A-Za-z0-9_\-\/.]+(:[A-Za-z0-9_\-.]+)*(:\*)?$/', $ability) === 1) {
                $normalized[] = $ability;
            }
        }

        return array_values(array_unique($normalized));
    }
}
