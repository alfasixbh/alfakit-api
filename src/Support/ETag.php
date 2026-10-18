<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use Illuminate\Http\Request;

/**
 * Strong entity tags of API representations, and `If-None-Match` / `If-Match` matching.
 *
 * @internal
 */
final class ETag
{
    /**
     * @param  array<string, mixed>  $representation
     */
    public static function for(array $representation): string
    {
        return '"'.hash('sha256', (string) json_encode($representation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'"';
    }

    /**
     * Whether `If-None-Match` names this tag (weak comparison, as RFC 9110 asks for GET).
     */
    public static function matchesIfNoneMatch(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');

        if ($header === null || $header === '') {
            return false;
        }

        foreach (self::tags($header) as $tag) {
            if ($tag === '*' || self::opaque($tag) === self::opaque($etag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * False when `If-Match` is sent and names neither this tag nor `*` (strong comparison).
     */
    public static function satisfiesIfMatch(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-Match');

        if ($header === null || $header === '') {
            return true;
        }

        foreach (self::tags($header) as $tag) {
            if ($tag === '*' || $tag === $etag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function tags(string $header): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $header)), static fn (string $tag): bool => $tag !== ''));
    }

    private static function opaque(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
