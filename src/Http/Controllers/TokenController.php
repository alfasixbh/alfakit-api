<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Controllers;

use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * `GET /api/v1/token`: what the presented token is (name, abilities, expiry, user and
 * tenant), so clients can check their setup.
 */
class TokenController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = ApiRequest::token($request) ?? throw ProblemException::make(401, (string) __('alfakit-api::api.problems.missing_token'), 'unauthenticated');
        $panel = ApiRequest::panel($request);
        $user = $token->getUser();
        $tenant = $token->getTenant();

        return new JsonResponse(['data' => [
            'id' => $token->getTokenId(),
            'name' => $token->getTokenName(),
            'abilities' => $token->getAbilities(),
            'expires_at' => $token->getExpiresAt() === null ? null : Carbon::instance($token->getExpiresAt())->toJSON(),
            'last_used_at' => $token->getLastUsedAt() === null ? null : Carbon::instance($token->getLastUsedAt())->toJSON(),
            'user' => $user === null ? null : ['id' => $user->getAuthIdentifier(), 'name' => $panel->getUserName($user)],
            'tenant' => $tenant instanceof Model ? ['id' => $panel->getTenantRouteKey($tenant), 'name' => $panel->getTenantLabel($tenant)] : null,
        ]], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
