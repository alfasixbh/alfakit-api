<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\OpenApi\OpenApiGenerator;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('serves an OpenAPI 3.1 document of the exposed resources', function (): void {
    $response = api('GET', '/api/v1/openapi.json', apiToken(apiUser()))->assertOk()->assertHeader('ETag');
    $document = $response->json();

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info']['version'])->toBe('v1')
        ->and($document['servers'][0]['url'])->toBe('http://localhost/api/v1')
        ->and($document['components']['securitySchemes']['bearerAuth'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and(array_keys($document['paths']))->toContain('/articles', '/articles/{record}', '/articles/bulk/publish', '/articles/bulk/delete', '/notes', '/notes/{record}', '/token')
        ->and(array_keys($document['paths']['/articles']))->toBe(['get', 'post'])
        ->and(array_keys($document['paths']['/articles/{record}']))->toBe(['get', 'patch', 'put', 'delete'])
        ->and($document['paths']['/articles']['get']['x-ability'])->toBe('articles:read')
        ->and($document['paths']['/articles/bulk/publish']['post']['x-ability'])->toBe('articles:bulk:publish');

    $parameters = array_column($document['paths']['/articles']['get']['parameters'], 'name');
    expect($parameters)->toContain('per_page', 'sort', 'search', 'filter[status]', 'filter[is_featured]', 'fields', 'include');

    $sort = collect($document['paths']['/articles']['get']['parameters'])->firstWhere('name', 'sort');
    expect($sort['schema']['enum'])->toBe(['title', 'published_at', '-title', '-published_at']);

    $article = $document['components']['schemas']['Article'];
    expect(array_keys($article['properties']))->toBe(['id', 'title', 'status', 'body', 'is_featured', 'is_private', 'published_at', 'created_at', 'updated_at', 'author'])
        ->and($article['properties']['id']['type'])->toBe(['integer', 'null'])
        ->and($article['properties']['is_featured']['type'])->toBe(['boolean', 'null'])
        ->and($article['properties']['published_at'])->toMatchArray(['format' => 'date-time']);

    $input = $document['components']['schemas']['ArticleCreateInput'];
    expect($input['required'])->toBe(['title', 'status'])
        ->and($input['properties']['title'])->toMatchArray(['type' => 'string', 'maxLength' => 120])
        ->and($input['properties']['status']['enum'])->toBe(['draft', 'published'])
        ->and($input['properties']['is_featured']['type'])->toBe(['boolean', 'null'])
        ->and($input['properties']['published_at'])->toMatchArray(['format' => 'date-time'])
        ->and($document['components']['schemas']['ArticleUpdateInput'])->not->toHaveKey('required');

    expect($document['components']['responses']['ValidationFailed']['content'])->toHaveKey('application/problem+json')
        ->and($document['paths']['/articles']['post']['responses'])->toHaveKeys([201, 401, 403, 422, 429])
        ->and($document['components']['schemas']['Note']['properties'])->toHaveKeys(['id', 'body', 'shout']);
});

it('adds the tenant segment on tenancy panels', function (): void {
    $document = OpenApiGenerator::make(alfakit()->getPanel('api-team'), ApiPlugin::get(alfakit()->getPanel('api-team')) ?? ApiPlugin::make())->generate();

    expect(array_keys($document['paths']))->toContain('/{tenant}/articles', '/{tenant}/articles/{record}')
        ->and($document['components']['parameters'])->toHaveKey('Tenant')
        ->and($document['servers'][0]['url'])->toBe('http://localhost/api/team/v1');

    $tokenTenant = OpenApiGenerator::make(alfakit()->getPanel('api-tt'), ApiPlugin::get(alfakit()->getPanel('api-tt')) ?? ApiPlugin::make())->generate();

    expect(array_keys($tokenTenant['paths']))->toContain('/articles')->and($tokenTenant['components']['parameters'])->not->toHaveKey('Tenant');
});

it('needs a token for the document (any ability)', function (): void {
    api('GET', '/api/v1/openapi.json', null)->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
    api('GET', '/api/v1/openapi.json', apiToken(apiUser(), ['notes:read']))->assertOk();
});
