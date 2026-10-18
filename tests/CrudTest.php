<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Note;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource\Pages\CreateArticle;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource\Pages\EditArticle;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;
use function AlfaSix\AlfakitApi\Tests\article;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

it('lists records with pagination metadata and links', function (): void {
    $token = apiToken(apiUser());
    foreach (range(1, 3) as $i) {
        article(['title' => "Article {$i}"]);
    }

    $response = api('GET', '/api/v1/articles?per_page=2', $token);

    $response->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonCount(2, 'data')
        // The table's default order (newest first), as in the panel.
        ->assertJsonPath('data.0.title', 'Article 3');

    expect($response->json('links.next'))->toContain('page=2')->toContain('per_page=2')
        ->and($response->headers->get('X-RateLimit-Limit'))->toBe('1000');

    api('GET', '/api/v1/articles?per_page=2&page=2', $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Article 1');
});

it('sorts, searches and filters with the resource table', function (): void {
    $token = apiToken(apiUser());
    article(['title' => 'Banana', 'status' => 'published', 'is_featured' => true]);
    article(['title' => 'Apple', 'status' => 'draft']);
    article(['title' => 'Cherry', 'status' => 'published']);

    expect(api('GET', '/api/v1/articles?sort=title', $token)->json('data.*.title'))->toBe(['Apple', 'Banana', 'Cherry'])
        ->and(api('GET', '/api/v1/articles?sort=-title', $token)->json('data.*.title'))->toBe(['Cherry', 'Banana', 'Apple'])
        ->and(api('GET', '/api/v1/articles?search=an', $token)->json('data.*.title'))->toBe(['Banana'])
        ->and(api('GET', '/api/v1/articles?filter[status]=published&sort=title', $token)->json('data.*.title'))->toBe(['Banana', 'Cherry'])
        ->and(api('GET', '/api/v1/articles?filter[is_featured]=true', $token)->json('data.*.title'))->toBe(['Banana'])
        ->and(api('GET', '/api/v1/articles?filter[is_featured]=false&sort=title', $token)->json('data.*.title'))->toBe(['Apple', 'Cherry']);
});

it('rejects unknown sorts, filters, fields and includes with a 400 problem', function (string $query, string $parameter): void {
    $token = apiToken(apiUser());

    api('GET', '/api/v1/articles?'.$query, $token)
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://alfakit.dev/problems/invalid-parameter')
        ->assertJsonPath('parameter', $parameter);
})->with([
    'unsortable column' => ['sort=status', 'sort'],
    'unknown filter' => ['filter[secret]=1', 'filter'],
    'hidden field' => ['fields=secret_note', 'fields'],
    'unknown include' => ['include=team', 'include'],
    'per page too large' => ['per_page=1000', 'per_page'],
    'bad page' => ['page=zero', 'page'],
]);

it('shows a record with sparse fieldsets and whitelisted includes', function (): void {
    $user = apiUser('Grace');
    $token = apiToken($user);
    $article = article(['title' => 'Readable', 'author_id' => $user->getKey(), 'published_at' => '2026-02-03 04:05:06']);

    api('GET', '/api/v1/articles/'.$article->getKey(), $token)
        ->assertOk()
        ->assertJsonPath('data.id', $article->getKey())
        ->assertJsonPath('data.title', 'Readable')
        ->assertJsonPath('data.is_featured', false)
        ->assertJsonPath('data.published_at', '2026-02-03T04:05:06.000000Z');

    api('GET', '/api/v1/articles/'.$article->getKey().'?fields=title&include=author', $token)
        ->assertOk()
        ->assertExactJson(['data' => ['title' => 'Readable', 'author' => ['id' => $user->getKey(), 'name' => 'Grace']]]);

    api('GET', '/api/v1/articles/999', $token)->assertNotFound()->assertJsonPath('type', 'https://alfakit.dev/problems/not-found');
});

it('creates records through the resource form, its validation and its page hooks', function (): void {
    CreateArticle::$calledHooks = [];
    $user = apiUser();
    $token = apiToken($user);

    $response = api('POST', '/api/v1/articles', $token, ['title' => 'Fresh', 'status' => 'published', 'is_featured' => true, 'author_id' => 999, 'team_id' => 5]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Fresh')
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.is_featured', true);

    $article = Article::query()->sole();

    expect($response->headers->get('Location'))->toEndWith('/api/v1/articles/'.$article->getKey())
        ->and($response->headers->get('ETag'))->not->toBeNull()
        // `mutateFormDataBeforeCreate()` set the author; non-form input (team_id) was ignored.
        ->and($article->getAttribute('author_id'))->toBe($user->getKey())
        ->and($article->getAttribute('team_id'))->toBeNull()
        ->and(CreateArticle::$calledHooks)->toBe(['afterCreate:'.$article->getKey()]);
});

it('uses the form defaults for fields a create request leaves out', function (): void {
    api('POST', '/api/v1/articles', apiToken(apiUser()), ['title' => 'Defaults'])->assertCreated()->assertJsonPath('data.status', 'draft');
});

it('updates records partially and runs the edit page hooks', function (): void {
    EditArticle::$calledHooks = [];
    $user = apiUser();
    $article = article(['title' => 'Old', 'status' => 'published', 'author_id' => $user->getKey(), 'body' => 'Keep me']);

    api('PATCH', '/api/v1/articles/'.$article->getKey(), apiToken($user), ['title' => 'New'])
        ->assertOk()
        ->assertJsonPath('data.title', 'New')
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.body', 'Keep me');

    expect($article->refresh()->getAttribute('title'))->toBe('New')
        ->and(EditArticle::$calledHooks)->toBe(['afterSave:'.$article->getKey()]);
});

it('deletes records', function (): void {
    $article = article();

    api('DELETE', '/api/v1/articles/'.$article->getKey(), apiToken(apiUser(admin: true)))->assertNoContent();

    expect(Article::query()->count())->toBe(0);
});

it('serves resources that opt in with ExposesApi and have no pages', function (): void {
    $token = apiToken(apiUser());

    api('POST', '/api/v1/notes', $token, ['body' => 'remember'])->assertCreated()->assertExactJson(['data' => ['id' => 1, 'body' => 'remember', 'shout' => 'REMEMBER']]);
    api('PATCH', '/api/v1/notes/1', $token, ['body' => 'forget'])->assertOk()->assertJsonPath('data.shout', 'FORGET');
    api('GET', '/api/v1/notes?search=forg', $token)->assertOk()->assertJsonCount(1, 'data');
    api('POST', '/api/v1/notes', $token, ['body' => 'no'])->assertUnprocessable()->assertJsonValidationErrors('body');

    expect(Note::query()->count())->toBe(1);
});

it('runs exposed bulk actions on the selected records only', function (): void {
    $token = apiToken(apiUser(admin: true));
    $a = article(['title' => 'A']);
    $b = article(['title' => 'B']);
    $c = article(['title' => 'C']);

    api('POST', '/api/v1/articles/bulk/publish', $token, ['ids' => [$a->getKey(), $b->getKey()]])
        ->assertOk()
        ->assertExactJson(['data' => ['action' => 'publish', 'count' => 2]]);

    expect(Article::query()->where('status', 'published')->pluck('title')->all())->toBe(['A', 'B']);

    api('POST', '/api/v1/articles/bulk/delete', $token, ['ids' => [$c->getKey()]])->assertOk();
    expect(Article::query()->count())->toBe(2);

    api('POST', '/api/v1/articles/bulk/archive', $token, ['ids' => [$a->getKey()]])->assertNotFound();
    api('POST', '/api/v1/articles/bulk/publish', $token, ['ids' => []])->assertUnprocessable()->assertJsonPath('errors.ids.0', fn (string $message): bool => $message !== '');
});

it('answers unknown API URLs with a problem document', function (): void {
    api('GET', '/api/v1/unknown/thing', apiToken(apiUser()))
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 404)
        ->assertJsonPath('instance', '/api/v1/unknown/thing');
});

it('describes the current token', function (): void {
    $user = apiUser('Linus');

    api('GET', '/api/v1/token', apiToken($user, ['articles:read'], name: 'CI'))
        ->assertOk()
        ->assertJsonPath('data.name', 'CI')
        ->assertJsonPath('data.abilities', ['articles:read'])
        ->assertJsonPath('data.user.name', 'Linus')
        ->assertJsonPath('data.tenant', null);
});
