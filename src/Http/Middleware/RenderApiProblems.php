<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Middleware;

use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\Problem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The outermost API middleware: marks the request as an API request and turns every error
 * response (aborts, exceptions, validation failures) into an `application/problem+json`
 * document, whatever the application's exception handler rendered.
 */
class RenderApiProblems
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(ApiRequest::ATTRIBUTE, true);
        // Validation and error rendering always answer in JSON.
        $request->headers->set('Accept', 'application/json');

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            report($exception);

            return $this->render($request, $exception, Problem::fromThrowable($exception));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Responses depend on the token: shared caches must not mix them up.
        $response->setVary(['Authorization', 'Accept-Language'], replace: false);

        if ($response->getStatusCode() < 400 || str_starts_with((string) $response->headers->get('Content-Type'), Problem::CONTENT_TYPE)) {
            return $response;
        }

        $exception = $response instanceof IlluminateResponse || $response instanceof JsonResponse || $response instanceof RedirectResponse ? $response->exception : null;
        $problem = $exception instanceof Throwable ? Problem::fromThrowable($exception) : Problem::make($response->getStatusCode());

        $rendered = $this->render($request, $exception instanceof Throwable ? $exception : null, $problem);

        // Keep headers set further in (rate limits, `Retry-After`, `WWW-Authenticate`).
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'WWW-Authenticate', 'Allow'] as $header) {
            if ($response->headers->has($header) && ! $rendered->headers->has($header)) {
                $rendered->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $rendered;
    }

    protected function render(Request $request, ?Throwable $exception, Problem $problem): Response
    {
        if ($exception !== null && $problem->status >= 500 && (bool) config('app.debug')) {
            $problem = Problem::make($problem->status, $problem->detail, 'server-error', ['debug' => ['exception' => $exception::class, 'message' => $exception->getMessage()]], $problem->headers);
        }

        $response = $problem->toResponse($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
