<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Controllers;

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\OpenApi\OpenApiGenerator;
use AlfaSix\AlfakitApi\Pages\ApiDocumentation;
use Illuminate\Http\JsonResponse;

/**
 * `{panel}/api-docs/openapi.json`: the OpenAPI document for signed-in panel users (the
 * API reference page links to it), behind the panel's own authentication.
 */
class DocumentationController
{
    public function __invoke(): JsonResponse
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $plugin = ApiPlugin::get($panel);
        $page = $plugin?->getDocumentationPage() ?? ApiDocumentation::class;

        abort_unless($plugin !== null && $page::canAccess(), 403);

        return new JsonResponse(OpenApiGenerator::make($panel, $plugin)->generate(), 200, [
            'Content-Disposition' => 'inline; filename="openapi.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
