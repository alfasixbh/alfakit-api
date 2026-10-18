<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * `Idempotency-Key` support for `store`: the first successful response is kept for the
 * plugin's idempotency TTL and replayed (with `Idempotent-Replayed: true`) when the same
 * client sends the same key again. Reusing a key with another payload is a 422; a request
 * while the first one is still running is a 409.
 *
 * @internal
 */
final class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    /**
     * @param  Closure(): JsonResponse  $callback
     */
    public static function run(Request $request, ApiPlugin $plugin, string $scope, Closure $callback): JsonResponse
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            return $callback();
        }

        if (mb_strlen($key) > 255) {
            throw ProblemException::make(400, (string) __('alfakit-api::api.problems.invalid_idempotency_key'), 'invalid-parameter', ['parameter' => self::HEADER]);
        }

        $cache = Cache::store($plugin->getIdempotencyStore());
        $cacheKey = 'alfakit-api:idempotency:'.hash('sha256', $scope.'|'.$key);
        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode(self::canonical($request->all())));

        $stored = $cache->get($cacheKey);

        if (is_array($stored)) {
            return self::replay($stored, $fingerprint);
        }

        $store = $cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock($cacheKey.':lock', 30) : null;

        if ($lock !== null && ! $lock->get()) {
            throw ProblemException::make(409, (string) __('alfakit-api::api.problems.idempotency_in_progress'), 'idempotency-in-progress');
        }

        try {
            // Another request may have finished while this one waited for the lock.
            $stored = $cache->get($cacheKey);

            if (is_array($stored)) {
                return self::replay($stored, $fingerprint);
            }

            $response = $callback();

            if ($response->isSuccessful()) {
                self::remember($cache, $cacheKey, $fingerprint, $response, $plugin->getIdempotencyTtl());
            }

            return $response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * @param  array<mixed>  $stored
     */
    private static function replay(array $stored, string $fingerprint): JsonResponse
    {
        if (($stored['fingerprint'] ?? null) !== $fingerprint) {
            throw ProblemException::make(422, (string) __('alfakit-api::api.problems.idempotency_mismatch'), 'idempotency-mismatch');
        }

        $headers = is_array($stored['headers'] ?? null) ? $stored['headers'] : [];

        return new JsonResponse($stored['body'] ?? null, (int) ($stored['status'] ?? 200), [...$headers, 'Idempotent-Replayed' => 'true'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function remember(Repository $cache, string $key, string $fingerprint, JsonResponse $response, int $ttlMinutes): void
    {
        $headers = [];

        foreach (['Location', 'ETag'] as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = (string) $response->headers->get($header);
            }
        }

        $cache->put($key, [
            'fingerprint' => $fingerprint,
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
            'headers' => $headers,
        ], now()->addMinutes($ttlMinutes));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
