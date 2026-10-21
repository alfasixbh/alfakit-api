<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Models\ApiToken;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;
use function AlfaSix\AlfakitApi\Tests\article;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

// ---------------------------------------------------------------- Tokens

it('requires a valid bearer token', function (?string $token, string $detailKey): void {
    api('GET', '/api/v1/articles', $token)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('WWW-Authenticate')
        ->assertJsonPath('type', 'https://alfakit.dev/problems/unauthenticated')
        ->assertJsonPath('detail', __('alfakit-api::api.problems.'.$detailKey));
})->with([
    'no token' => [null, 'missing_token'],
    'garbage' => ['not-a-token', 'invalid_token'],
    'unknown id' => ['999|'.str_repeat('a', 40), 'invalid_token'],
]);

it('rejects tampered, expired, revoked and other panels\' tokens', function (): void {
    $user = apiUser();
    $token = apiToken($user);
    [$id] = explode('|', $token);

    api('GET', '/api/v1/articles', $id.'|'.str_repeat('x', 40))->assertUnauthorized();
    api('GET', '/api/v1/articles', apiToken($user, expiresAt: now()->subMinute()))->assertUnauthorized()->assertJsonPath('detail', __('alfakit-api::api.problems.expired_token'));
    api('GET', '/api/v1/articles', apiToken($user, panel: 'api-team'))->assertUnauthorized();

    api('GET', '/api/v1/articles', $token)->assertOk();
    expect(ApiPlugin::get(alfakit()->getPanel('api-admin'))?->getTokenRepository()->revoke($user, $id))->toBeTrue();
    api('GET', '/api/v1/articles', $token)->assertUnauthorized();
});

it('stores only a hash of the token and records its last use', function (): void {
    $token = apiToken(apiUser());
    [$id, $secret] = explode('|', $token);
    $model = ApiToken::query()->findOrFail($id);

    expect($model->getAttribute('token'))->toBe(hash('sha256', $secret))->not->toContain($secret)
        ->and($model->toArray())->not->toHaveKey('token')
        ->and($model->getAttribute('last_used_at'))->toBeNull();

    api('GET', '/api/v1/articles', $token)->assertOk();

    expect($model->refresh()->getAttribute('last_used_at'))->not->toBeNull()
        ->and($model->getAttribute('last_used_ip'))->toBe('127.0.0.1');
});

it('limits tokens to their abilities', function (): void {
    $user = apiUser(admin: true);
    $article = article();
    $readOnly = apiToken($user, ['articles:read']);

    api('GET', '/api/v1/articles', $readOnly)->assertOk();
    api('GET', '/api/v1/articles/'.$article->getKey(), $readOnly)->assertOk();
    api('POST', '/api/v1/articles', $readOnly, ['title' => 'Nope'])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://alfakit.dev/problems/insufficient-ability')
        ->assertJsonPath('ability', 'articles:create');
    api('PATCH', '/api/v1/articles/'.$article->getKey(), $readOnly, ['title' => 'Nope'])->assertForbidden();
    api('DELETE', '/api/v1/articles/'.$article->getKey(), $readOnly)->assertForbidden();
    api('POST', '/api/v1/articles/bulk/publish', $readOnly, ['ids' => [$article->getKey()]])->assertForbidden()->assertJsonPath('ability', 'articles:bulk:publish');
    api('GET', '/api/v1/notes', $readOnly)->assertForbidden();

    $wildcard = apiToken($user, ['articles:*']);
    api('POST', '/api/v1/articles/bulk/publish', $wildcard, ['ids' => [$article->getKey()]])->assertOk();
    api('GET', '/api/v1/notes', $wildcard)->assertForbidden();

    expect(Article::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- Policies

it('authorises every call with the resource policy', function (): void {
    $author = apiUser('Author');
    $other = apiUser('Other');
    $mine = article(['author_id' => $author->getKey()]);
    $private = article(['is_private' => true]);
    $token = apiToken($other);

    api('GET', '/api/v1/articles/'.$private->getKey(), $token)->assertForbidden()->assertJsonPath('type', 'https://alfakit.dev/problems/forbidden');
    api('GET', '/api/v1/articles/'.$private->getKey(), apiToken(apiUser(admin: true)))->assertOk();
    api('PATCH', '/api/v1/articles/'.$mine->getKey(), $token, ['title' => 'Hijacked'])->assertForbidden();
    api('DELETE', '/api/v1/articles/'.$mine->getKey(), $token)->assertForbidden();
    api('POST', '/api/v1/articles/bulk/delete', $token, ['ids' => [$mine->getKey()]])->assertForbidden();
    api('PATCH', '/api/v1/articles/'.$mine->getKey(), apiToken($author), ['title' => 'Mine'])->assertOk();

    expect($mine->refresh()->getAttribute('title'))->toBe('Mine')->and(Article::query()->count())->toBe(2);
});

// ---------------------------------------------------------------- Validation

it('validates input with the resource form rules', function (): void {
    $token = apiToken(apiUser());

    api('POST', '/api/v1/articles', $token, ['title' => '', 'status' => 'archived'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://alfakit.dev/problems/validation')
        ->assertJsonPath('status', 422)
        ->assertJsonValidationErrors(['title', 'status']);

    api('POST', '/api/v1/articles', $token, ['title' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('title');

    $article = article(['author_id' => null]);
    api('PATCH', '/api/v1/articles/'.$article->getKey(), apiToken(apiUser(admin: true)), ['title' => ''])->assertUnprocessable()->assertJsonValidationErrors('title');

    api('POST', '/api/v1/articles', $token, [['title' => 'list body']])->assertStatus(400)->assertJsonPath('type', 'https://alfakit.dev/problems/invalid-body');

    expect(Article::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- Output

it('never exposes hidden attributes, even when a form field or include lists them', function (): void {
    $user = apiUser('Hidden');
    $token = apiToken($user, ['*']);
    $article = article(['secret_note' => 'classified', 'author_id' => $user->getKey()]);

    $show = api('GET', '/api/v1/articles/'.$article->getKey().'?include=author', $token)->assertOk();
    $index = api('GET', '/api/v1/articles?include=author', $token)->assertOk();
    $created = api('POST', '/api/v1/articles', $token, ['title' => 'Kept', 'secret_note' => 'shh'])->assertCreated();

    foreach ([$show, $index, $created] as $response) {
        expect($response->getContent())->not->toContain('classified')->not->toContain('secret_note')->not->toContain('shh')->not->toContain('password');
    }

    // Writable through the form, but still never returned.
    expect(Article::query()->where('title', 'Kept')->value('secret_note'))->toBe('shh')
        ->and(array_keys($show->json('data')))->toBe(['id', 'title', 'status', 'body', 'is_featured', 'is_private', 'published_at', 'created_at', 'updated_at', 'author']);
});

it('keeps a hidden attribute an update leaves out, and never returns it', function (): void {
    $user = apiUser('Hidden update');
    $token = apiToken($user, ['*']);
    $article = article(['secret_note' => 'keep', 'author_id' => $user->getKey()]);

    $response = api('PATCH', '/api/v1/articles/'.$article->getKey(), $token, ['title' => 'T2'])->assertOk();

    expect($article->refresh()->title)->toBe('T2')
        ->and($article->secret_note)->toBe('keep')
        ->and($response->getContent())->not->toContain('keep')->not->toContain('secret_note');

    // Sent, it is written.
    api('PATCH', '/api/v1/articles/'.$article->getKey(), $token, ['secret_note' => 'changed'])->assertOk();

    expect($article->refresh()->secret_note)->toBe('changed');
});

// ---------------------------------------------------------------- HTTP features

it('rate limits per token with headers and a 429 problem', function (): void {
    $user = apiUser();
    $limited = apiToken($user, name: 'limited');

    api('GET', '/api/v1/articles', $limited)->assertOk()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
    api('GET', '/api/v1/articles', $limited)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    api('GET', '/api/v1/articles', $limited)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('type', 'https://alfakit.dev/problems/rate-limited');

    // Other tokens have their own budget.
    api('GET', '/api/v1/articles', apiToken($user))->assertOk();
});

it('supports ETag / If-None-Match on show and If-Match on writes', function (): void {
    $user = apiUser(admin: true);
    $token = apiToken($user);
    $article = article();
    $url = '/api/v1/articles/'.$article->getKey();

    $etag = (string) api('GET', $url, $token)->assertOk()->headers->get('ETag');

    expect($etag)->toStartWith('"');

    api('GET', $url, $token, headers: ['If-None-Match' => $etag])->assertStatus(304);
    api('GET', $url, $token, headers: ['If-None-Match' => 'W/'.$etag])->assertStatus(304);
    api('GET', $url, $token, headers: ['If-None-Match' => '"other"'])->assertOk();

    api('PATCH', $url, $token, ['title' => 'Stale'], ['If-Match' => '"stale"'])->assertStatus(412)->assertJsonPath('type', 'https://alfakit.dev/problems/precondition-failed');
    $newEtag = api('PATCH', $url, $token, ['title' => 'Fresh'], ['If-Match' => $etag])->assertOk()->headers->get('ETag');

    expect($newEtag)->not->toBe($etag);
    api('GET', $url, $token, headers: ['If-None-Match' => $etag])->assertOk();
    api('DELETE', $url, $token, headers: ['If-Match' => $etag])->assertStatus(412);
    api('DELETE', $url, $token, headers: ['If-Match' => (string) $newEtag])->assertNoContent();
});

it('replays store responses for a repeated Idempotency-Key', function (): void {
    $token = apiToken(apiUser());
    $headers = ['Idempotency-Key' => 'order-123'];

    $first = api('POST', '/api/v1/articles', $token, ['title' => 'Once'], $headers)->assertCreated();
    $second = api('POST', '/api/v1/articles', $token, ['title' => 'Once'], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(Article::query()->count())->toBe(1);

    api('POST', '/api/v1/articles', $token, ['title' => 'Different'], $headers)->assertUnprocessable()->assertJsonPath('type', 'https://alfakit.dev/problems/idempotency-mismatch');

    // Keys are scoped to the token.
    api('POST', '/api/v1/articles', apiToken(apiUser()), ['title' => 'Once'], $headers)->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(Article::query()->count())->toBe(2);
});
