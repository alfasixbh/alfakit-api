<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Controllers;

use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\OpenApi\OpenApiGenerator;
use AlfaSix\AlfakitApi\Support\ETag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /api/v1/openapi.json`: the OpenAPI 3.1 document of the panel's API.
 */
class OpenApiController
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        $document = OpenApiGenerator::make(ApiRequest::panel($request), ApiRequest::plugin($request))->generate();
        $etag = ETag::for($document);
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (ETag::matchesIfNoneMatch($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return new JsonResponse($document, 200, $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
