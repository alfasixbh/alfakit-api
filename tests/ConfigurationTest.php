<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\NoteResource;
use AlfaSix\AlfakitApi\Tokens\Abilities;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

it('matches abilities exactly or through wildcards', function (array $granted, string $ability, bool $allowed): void {
    expect(Abilities::allows($granted, $ability))->toBe($allowed);
})->with([
    'exact' => [['posts:read'], 'posts:read', true],
    'other operation' => [['posts:read'], 'posts:create', false],
    'resource wildcard' => [['posts:*'], 'posts:bulk:publish', true],
    'wildcard of another resource' => [['post:*'], 'posts:read', false],
    'everything' => [['*'], 'notes:delete', true],
    'nothing' => [[], 'posts:read', false],
]);

it('keeps only well-formed abilities', function (): void {
    expect(Abilities::normalize(['posts:read', ' posts:read ', 'posts:*', '*', 'bad ability', 12, 'x:bulk:publish']))->toBe(['posts:read', 'posts:*', '*', 'x:bulk:publish']);
});

it('configures operations and refuses unknown ones', function (): void {
    expect(ResourceApi::make(ArticleResource::class)->readOnly()->getOperations())->toBe(['index', 'show'])
        ->and(ResourceApi::make(ArticleResource::class)->operations(['destroy', 'index'])->getOperations())->toBe(['index', 'destroy'])
        ->and(ResourceApi::make(ArticleResource::class)->getSlug())->toBe('articles');

    ResourceApi::make(ArticleResource::class)->operations(['index', 'export']);
})->throws(InvalidArgumentException::class);

it('refuses two resources with the same slug', function (): void {
    ApiPlugin::make()
        ->resources([ArticleResource::class, ResourceApi::make(NoteResource::class)->slug('articles')])
        ->getResourceApis(alfakit()->getPanel('api-admin'));
})->throws(InvalidArgumentException::class);

it('merges listed resources with resources that implement ExposesApi', function (): void {
    $plugin = ApiPlugin::get(alfakit()->getPanel('api-admin'));

    expect(array_keys($plugin?->getResourceApis(alfakit()->getPanel('api-admin')) ?? []))->toBe(['articles', 'notes'])
        ->and($plugin?->getBasePath(alfakit()->getPanel('api-admin')))->toBe('api/v1')
        ->and(ApiPlugin::make()->getBasePath(alfakit()->getPanel('api-team')))->toBe('api/api-team/v1');
});
