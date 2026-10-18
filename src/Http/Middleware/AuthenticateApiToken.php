<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Middleware;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates `Authorization: Bearer {token}` against the panel's token repository and
 * signs the token's user in on the panel's guard for this request only (nothing is
 * written to a session). `optional` lets requests without a token through.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $panel = ApiRequest::panel($request);
        $plugin = ApiRequest::plugin($request);
        $plainText = $request->bearerToken();

        if ($plainText === null || $plainText === '') {
            if ($mode === 'optional') {
                return $next($request);
            }

            throw $this->unauthenticated((string) __('alfakit-api::api.problems.missing_token'));
        }

        $repository = $plugin->getTokenRepository();
        $token = $repository->find($plainText, $panel->getId());

        if ($token === null) {
            throw $this->unauthenticated((string) __('alfakit-api::api.problems.invalid_token'));
        }

        if ($token->isExpired()) {
            throw $this->unauthenticated((string) __('alfakit-api::api.problems.expired_token'));
        }

        $user = $token->getUser();

        if ($user === null) {
            throw $this->unauthenticated((string) __('alfakit-api::api.problems.invalid_token'));
        }

        if ($user instanceof CanAccessPanel && ! $user->canAccessPanel($panel)) {
            throw ProblemException::make(403, (string) __('alfakit-api::api.problems.panel_access'), 'forbidden');
        }

        // Policies, `Resource::can()` and `auth()->user()` all see the token's user.
        $guard = $panel->auth();
        $previous = method_exists($guard, 'hasUser') && $guard->hasUser() ? $guard->user() : null;
        Auth::shouldUse($panel->getAuthGuard());
        $guard->setUser($user);

        $request->attributes->set(ApiRequest::TOKEN_ATTRIBUTE, $token);
        $request->setUserResolver(static fn () => $user);

        $repository->touch($token, $request->ip());

        try {
            return $next($request);
        } finally {
            // The token's user is signed in for this request only (long-running workers and
            // tests reuse the guard instance).
            if ($previous !== null) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    protected function unauthenticated(string $detail): ProblemException
    {
        return ProblemException::make(401, $detail, 'unauthenticated', headers: ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}
