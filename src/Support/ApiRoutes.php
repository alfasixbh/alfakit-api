<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use AlfaSix\Alfakit\Http\Middleware\SetUpPanel;
use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\Controllers\FallbackController;
use AlfaSix\AlfakitApi\Http\Controllers\OpenApiController;
use AlfaSix\AlfakitApi\Http\Controllers\ResourceController;
use AlfaSix\AlfakitApi\Http\Controllers\TokenController;
use AlfaSix\AlfakitApi\Http\Middleware\AuthenticateApiToken;
use AlfaSix\AlfakitApi\Http\Middleware\IdentifyApiTenant;
use AlfaSix\AlfakitApi\Http\Middleware\RenderApiProblems;
use AlfaSix\AlfakitApi\Http\Middleware\ThrottleApiRequests;
use Illuminate\Routing\Router;

/**
 * Registers the API routes of every panel with the plugin. They live outside the panel's
 * route group: no session, cookies or CSRF, only bearer tokens.
 *
 * ```
 * GET    /api/v1/openapi.json
 * GET    /api/v1/token
 * GET    /api/v1[/{tenant}]/{resource}
 * POST   /api/v1[/{tenant}]/{resource}
 * GET    /api/v1[/{tenant}]/{resource}/{record}
 * PATCH  /api/v1[/{tenant}]/{resource}/{record}   (and PUT)
 * DELETE /api/v1[/{tenant}]/{resource}/{record}
 * POST   /api/v1[/{tenant}]/{resource}/bulk/{action}
 * ```
 *
 * @internal
 */
final class ApiRoutes
{
    public static function register(Router $router): void
    {
        foreach (alfakit()->getPanels() as $panel) {
            $plugin = ApiPlugin::get($panel);

            if ($plugin !== null) {
                self::registerPanel($router, $panel, $plugin);
            }
        }
    }

    public static function routeName(Panel $panel, string $name): string
    {
        return 'alfakit-api.'.$panel->getId().'.'.$name;
    }

    public static function registerPanel(Router $router, Panel $panel, ApiPlugin $plugin): void
    {
        $defaults = [ApiRequest::PANEL_PARAMETER => $panel->getId()];
        $base = [RenderApiProblems::class, SetUpPanel::class.':'.$panel->getId()];
        $authenticated = [...$base, AuthenticateApiToken::class, ThrottleApiRequests::class, ...$plugin->getMiddleware()];

        $router->domain($plugin->getDomain())
            ->prefix($plugin->getBasePath($panel))
            ->name('alfakit-api.'.$panel->getId().'.')
            ->group(static function (Router $router) use ($panel, $plugin, $defaults, $base, $authenticated): void {
                $router->get('/openapi.json', OpenApiController::class)
                    ->name('openapi')
                    ->middleware($plugin->isDocumentationPublic()
                        ? [...$base, AuthenticateApiToken::class.':optional', ThrottleApiRequests::class]
                        : $authenticated)
                    ->setDefaults($defaults);

                $router->get('/token', TokenController::class)->name('token')->middleware($authenticated)->setDefaults($defaults);

                $tenantSegment = $panel->hasTenancy() && ! $plugin->isTenantFromToken() ? '{tenant}' : '';

                $router->prefix($tenantSegment)
                    ->middleware([...$authenticated, IdentifyApiTenant::class])
                    ->group(static function (Router $router) use ($panel, $plugin, $defaults): void {
                        foreach ($plugin->getResourceApis($panel) as $slug => $api) {
                            $resourceDefaults = [...$defaults, ApiRequest::RESOURCE_PARAMETER => $slug];
                            $name = 'resources.'.$slug.'.';

                            if ($api->getBulkActions() !== []) {
                                $router->post("/{$slug}/bulk/{action}", [ResourceController::class, 'bulk'])->name($name.'bulk')->setDefaults($resourceDefaults);
                            }

                            if ($api->hasOperation('index')) {
                                $router->get("/{$slug}", [ResourceController::class, 'index'])->name($name.'index')->setDefaults($resourceDefaults);
                            }

                            if ($api->hasOperation('store')) {
                                $router->post("/{$slug}", [ResourceController::class, 'store'])->name($name.'store')->setDefaults($resourceDefaults);
                            }

                            if ($api->hasOperation('show')) {
                                $router->get("/{$slug}/{record}", [ResourceController::class, 'show'])->name($name.'show')->setDefaults($resourceDefaults);
                            }

                            if ($api->hasOperation('update')) {
                                $router->match(['PUT', 'PATCH'], "/{$slug}/{record}", [ResourceController::class, 'update'])->name($name.'update')->setDefaults($resourceDefaults);
                            }

                            if ($api->hasOperation('destroy')) {
                                $router->delete("/{$slug}/{record}", [ResourceController::class, 'destroy'])->name($name.'destroy')->setDefaults($resourceDefaults);
                            }
                        }
                    });

                // Anything else under the prefix is a problem+json 404.
                $router->any('/{path?}', FallbackController::class)
                    ->where('path', '.*')
                    ->name('fallback')
                    ->middleware($base)
                    ->setDefaults($defaults);
            });
    }
}
