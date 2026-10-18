<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Middleware;

use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On tenancy panels: resolves the tenant from the `{tenant}` URL segment (or from the
 * token with `tenantFromToken()`), checks the user still belongs to it and that the token
 * was issued for it, and makes it the current tenant so every resource query is scoped.
 */
class IdentifyApiTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = ApiRequest::panel($request);

        if (! $panel->hasTenancy()) {
            return $next($request);
        }

        $token = ApiRequest::token($request);
        $user = $token?->getUser();

        if ($token === null || $user === null) {
            throw ProblemException::make(401, (string) __('alfakit-api::api.problems.missing_token'), 'unauthenticated', headers: ['WWW-Authenticate' => 'Bearer']);
        }

        $tokenTenant = $token->getTenant();

        if (ApiRequest::plugin($request)->isTenantFromToken()) {
            $key = $tokenTenant === null ? null : $panel->getTenantRouteKey($tokenTenant);
        } else {
            $key = $request->route()?->parameter('tenant');
        }

        $tenant = $key === null ? null : $panel->findTenantFor($key, $user);

        if ($tenant === null) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.tenant_not_found'), 'not-found');
        }

        // Tokens created on a tenancy panel only work for the tenant they were created in.
        if ($tokenTenant === null || $tokenTenant->getMorphClass() !== $tenant->getMorphClass() || (string) $tokenTenant->getKey() !== (string) $tenant->getKey()) {
            throw ProblemException::make(403, (string) __('alfakit-api::api.problems.wrong_tenant'), 'forbidden');
        }

        alfakit()->setTenant($tenant);
        $request->route()?->forgetParameter('tenant');

        return $next($request);
    }
}
