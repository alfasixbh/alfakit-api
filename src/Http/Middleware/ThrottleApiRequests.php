<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Middleware;

use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-token rate limiting (per IP address for anonymous requests), with the
 * `X-RateLimit-*` headers on every response and `Retry-After` on 429s.
 */
class ThrottleApiRequests
{
    public function __construct(protected RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $panel = ApiRequest::panel($request);
        $token = ApiRequest::token($request);
        $limit = ApiRequest::plugin($request)->getRateLimit($token, $token?->getUser());

        if ($limit === null) {
            return $next($request);
        }

        $key = 'alfakit-api:'.$panel->getId().':'.($token === null ? 'ip:'.$request->ip() : 'token:'.$token->getTokenId());

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $retryAfter = $this->limiter->availableIn($key);

            throw ProblemException::make(429, (string) __('alfakit-api::api.problems.too_many_requests'), 'rate-limited', ['retry_after' => $retryAfter], [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) (time() + $retryAfter),
            ]);
        }

        $this->limiter->hit($key, 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $limit - $this->limiter->attempts($key)));

        return $response;
    }
}
