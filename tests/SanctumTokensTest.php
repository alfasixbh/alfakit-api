<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Models\SanctumApiToken;
use AlfaSix\AlfakitApi\Tokens\SanctumTokenRepository;
use Laravel\Sanctum\PersonalAccessToken;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiTeam;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;
use function AlfaSix\AlfakitApi\Tests\article;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

/*
 * `ApiPlugin::make()->tokenRepository(new SanctumTokenRepository)`: API tokens stored as
 * Laravel Sanctum personal access tokens.
 */

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

function useSanctumTokens(bool $panelBound = true): void
{
    foreach (['api-admin', 'api-team', 'api-tt'] as $panel) {
        ApiPlugin::get(alfakit()->getPanel($panel))?->tokenRepository(new SanctumTokenRepository(panelBound: $panelBound));
    }
}

beforeEach(fn () => useSanctumTokens());

it('issues Sanctum tokens that authenticate the API with their abilities', function (): void {
    $user = apiUser(admin: true);
    $article = article();
    $token = apiToken($user, ['articles:read'], expiresAt: now()->addDay(), name: 'Sanctum bot');
    [$id, $secret] = explode('|', $token);

    $stored = PersonalAccessToken::query()->findOrFail($id);

    expect($stored->getAttribute('token'))->toBe(hash('sha256', $secret))
        ->and($stored->getAttribute('abilities'))->toBe(['articles:read', 'alfakit-panel:api-admin'])
        ->and($stored->getAttribute('tokenable_id'))->toBe($user->getKey())
        ->and($stored->getAttribute('last_used_at'))->toBeNull()
        ->and(strlen($secret))->toBe(48);

    api('GET', '/api/v1/articles/'.$article->getKey(), $token)->assertOk();
    api('POST', '/api/v1/articles', $token, ['title' => 'Nope'])->assertForbidden()->assertJsonPath('ability', 'articles:create');

    // The marker abilities never show.
    api('GET', '/api/v1/token', $token)->assertOk()
        ->assertJsonPath('data.name', 'Sanctum bot')
        ->assertJsonPath('data.abilities', ['articles:read']);

    expect($stored->refresh()->getAttribute('last_used_at'))->not->toBeNull();

    $wildcard = apiToken($user, ['articles:*']);
    api('POST', '/api/v1/articles/bulk/publish', $wildcard, ['ids' => [$article->getKey()]])->assertOk();
    api('GET', '/api/v1/notes', $wildcard)->assertForbidden();
});

it('rejects tampered, expired, revoked and other panels\' Sanctum tokens', function (): void {
    $user = apiUser();
    $token = apiToken($user);
    [$id] = explode('|', $token);

    api('GET', '/api/v1/articles', $id.'|'.str_repeat('x', 48))->assertUnauthorized();
    api('GET', '/api/v1/articles', apiToken($user, expiresAt: now()->subMinute()))->assertUnauthorized()
        ->assertJsonPath('detail', __('alfakit-api::api.problems.expired_token'));
    api('GET', '/api/v1/articles', apiToken($user, panel: 'api-team'))->assertUnauthorized();

    // `sanctum.expiration` applies too.
    config(['sanctum.expiration' => 60]);
    $this->travel(2)->hours();
    api('GET', '/api/v1/articles', $token)->assertUnauthorized();
    config(['sanctum.expiration' => null]);
    api('GET', '/api/v1/articles', $token)->assertOk();

    expect(ApiPlugin::get(alfakit()->getPanel('api-admin'))?->getTokenRepository()->revoke(apiUser('Other'), $id))->toBeFalse()
        ->and(ApiPlugin::get(alfakit()->getPanel('api-admin'))?->getTokenRepository()->revoke($user, $id))->toBeTrue();
    api('GET', '/api/v1/articles', $token)->assertUnauthorized();
});

it('accepts the app\'s own Sanctum tokens only when not panel-bound', function (): void {
    $user = apiUser(admin: true);
    article();
    $plain = SanctumApiToken::query()->forceCreate([
        'tokenable_type' => $user->getMorphClass(),
        'tokenable_id' => $user->getKey(),
        'name' => 'mobile',
        'token' => hash('sha256', $secret = str_repeat('m', 40)),
        'abilities' => ['articles:read'],
    ])->getKey().'|'.$secret;

    api('GET', '/api/v1/articles', $plain)->assertUnauthorized();

    useSanctumTokens(panelBound: false);

    api('GET', '/api/v1/articles', $plain)->assertOk();
    api('POST', '/api/v1/articles', $plain, ['title' => 'Nope'])->assertForbidden();
});

it('binds Sanctum tokens to their tenant', function (): void {
    $alpha = apiTeam('Alpha');
    $beta = apiTeam('Beta');
    $ada = apiUser('Ada', admin: true);
    $ada->teams()->attach([$alpha->getKey(), $beta->getKey()]);
    article(['title' => 'Alpha article', 'team_id' => $alpha->getKey()]);
    article(['title' => 'Beta article', 'team_id' => $beta->getKey()]);

    $token = apiToken($ada, panel: 'api-team', tenant: $alpha);

    api('GET', '/api/team/v1/alpha/articles', $token)->assertOk()->assertJsonCount(1, 'data');
    api('GET', '/api/team/v1/beta/articles', $token)->assertForbidden();
    api('GET', '/api/team/v1/alpha/articles', apiToken($ada, panel: 'api-team'))->assertForbidden();
    api('GET', '/api/tt/v1/articles', apiToken($ada, panel: 'api-tt', tenant: $beta))->assertOk()->assertJsonPath('data.0.title', 'Beta article');
});

it('lists, creates and revokes Sanctum tokens on the API tokens page', function (): void {
    $ada = apiUser('Ada');
    $bob = apiUser('Bob');
    apiToken($ada, name: 'Ada token');
    apiToken($ada, panel: 'api-team', name: 'Other panel token');
    $bobToken = apiToken($bob, name: 'Bob token');
    actingAs($ada);

    $props = static fn (): array => get('/api-admin/api-tokens', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk()->json('props.page');
    $content = json_encode($props());

    expect($content)->toContain('Ada token')->not->toContain('Bob token')->not->toContain('Other panel token')->not->toContain('alfakit-panel');

    postJson('/api-admin/_alfakit/actions', [
        'token' => $props()['token'],
        'component' => 'page',
        'action' => 'createToken',
        'data' => ['name' => 'From the page', 'expires_in' => '30', 'access' => 'all'],
    ], ['Referer' => 'http://localhost/api-admin/api-tokens'])->assertOk();

    $created = SanctumApiToken::query()->where('name', 'From the page')->sole();

    expect($created->getAbilities())->toBe(['*'])->and($created->getPanelId())->toBe('api-admin');

    // Bob's token is outside Ada's table.
    [$bobId] = explode('|', $bobToken);
    postJson('/api-admin/_alfakit/actions', ['token' => $props()['token'], 'component' => 'table', 'action' => 'revoke', 'record' => $bobId], ['Referer' => 'http://localhost/api-admin/api-tokens'])->assertNotFound();

    postJson('/api-admin/_alfakit/actions', ['token' => $props()['token'], 'component' => 'table', 'action' => 'revoke', 'record' => (string) $created->getKey()], ['Referer' => 'http://localhost/api-admin/api-tokens'])->assertOk();

    expect(SanctumApiToken::query()->pluck('name')->all())->toBe(['Ada token', 'Other panel token', 'Bob token']);
});
