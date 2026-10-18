<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Models\ApiToken;
use AlfaSix\AlfakitApi\Pages\ApiTokens;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser;
use Illuminate\Testing\TestResponse;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiTeam;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @return array<string, mixed>
 */
function apiPageProps(string $url): array
{
    $response = get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
    $response->assertOk();

    return $response->json('props.page');
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $extra
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function apiRunAction(string $panelPath, string $url, ?string $component, string $action, array $data = [], array $extra = []): TestResponse
{
    return postJson($panelPath.'/_alfakit/actions', [
        'token' => apiPageProps($url)['token'],
        'component' => $component,
        'action' => $action,
        'data' => $data,
        ...$extra,
    ], ['Referer' => 'http://localhost'.$url]);
}

it('creates a token with the chosen abilities and shows it exactly once', function (): void {
    $user = apiUser();
    actingAs($user);

    // Abilities the form doesn't offer are rejected by its validation.
    apiRunAction('/api-admin', '/api-admin/api-tokens', 'page', 'createToken', [
        'name' => 'Sneaky',
        'expires_in' => '30',
        'access' => 'custom',
        'abilities' => ['articles' => ['articles:read', '*']],
    ])->assertUnprocessable();

    apiRunAction('/api-admin', '/api-admin/api-tokens', 'page', 'createToken', [
        'name' => 'Deploy bot',
        'expires_in' => '30',
        'access' => 'custom',
        'abilities' => ['articles' => ['articles:read', 'articles:bulk:publish'], 'notes' => ['notes:create']],
    ])->assertOk();

    $token = ApiToken::query()->sole();

    expect($token->getTokenName())->toBe('Deploy bot')
        ->and($token->getAbilities())->toBe(['articles:read', 'articles:bulk:publish', 'notes:create'])
        ->and($token->getPanelId())->toBe('api-admin')
        ->and($token->getTenant())->toBeNull()
        ->and((int) round(now()->diffInDays($token->getExpiresAt())))->toBe(30);

    $content = json_encode(apiPageProps('/api-admin/api-tokens'));
    preg_match('/"(\d+\|[A-Za-z0-9]{40})"/', (string) $content, $matches);

    expect($matches)->toHaveCount(2);

    // The plain-text token works…
    $plainText = $matches[1];
    api('GET', '/api/v1/articles', $plainText)->assertOk();

    // …and is never shown again.
    expect(json_encode(apiPageProps('/api-admin/api-tokens')))->not->toContain($plainText)->toContain('Deploy bot');
});

it('creates full-access tokens and refuses tokens without abilities', function (): void {
    actingAs(apiUser());

    apiRunAction('/api-admin', '/api-admin/api-tokens', 'page', 'createToken', ['name' => 'Everything', 'expires_in' => 'never', 'access' => 'all'])->assertOk();
    apiRunAction('/api-admin', '/api-admin/api-tokens', 'page', 'createToken', ['name' => 'Nothing', 'expires_in' => '30', 'access' => 'custom', 'abilities' => []]);
    apiRunAction('/api-admin', '/api-admin/api-tokens', 'page', 'createToken', ['name' => 'Forever', 'expires_in' => '9999', 'access' => 'all']);

    expect(ApiToken::query()->pluck('name')->all())->toBe(['Everything'])
        ->and(ApiToken::query()->sole()->getAbilities())->toBe(['*'])
        ->and(ApiToken::query()->sole()->getExpiresAt())->toBeNull();
});

it('lists and revokes only the user\'s own tokens', function (): void {
    $ada = apiUser('Ada');
    $bob = apiUser('Bob');
    apiToken($ada, name: 'Ada token');
    $bobToken = apiToken($bob, name: 'Bob token');
    [$bobId] = explode('|', $bobToken);
    actingAs($ada);

    $props = json_encode(apiPageProps('/api-admin/api-tokens'));
    expect($props)->toContain('Ada token')->not->toContain('Bob token');

    // Bob's token is outside Ada's table query.
    apiRunAction('/api-admin', '/api-admin/api-tokens', 'table', 'revoke', extra: ['record' => $bobId])->assertNotFound();
    api('GET', '/api/v1/articles', $bobToken)->assertOk();

    $adaId = (string) ApiToken::query()->where('name', 'Ada token')->value('id');
    apiRunAction('/api-admin', '/api-admin/api-tokens', 'table', 'revoke', extra: ['record' => $adaId])->assertOk();

    expect(ApiToken::query()->pluck('name')->all())->toBe(['Bob token']);
});

it('binds tokens created on a tenancy panel to the current tenant', function (): void {
    $alpha = apiTeam('Alpha');
    $beta = apiTeam('Beta');
    $ada = apiUser('Ada');
    $ada->teams()->attach([$alpha->getKey(), $beta->getKey()]);
    actingAs($ada);
    apiToken($ada, panel: 'api-team', tenant: $beta, name: 'Beta token');

    apiRunAction('/api-team/alpha', '/api-team/alpha/api-tokens', 'page', 'createToken', ['name' => 'Alpha token', 'expires_in' => '30', 'access' => 'all'])->assertOk();

    $token = ApiToken::query()->where('name', 'Alpha token')->sole();

    expect($token->getTenant()?->getKey())->toBe($alpha->getKey())
        ->and(json_encode(apiPageProps('/api-team/alpha/api-tokens')))->toContain('Alpha token')->not->toContain('Beta token');
});

it('closes the token page to users canManageTokens() refuses', function (): void {
    $user = apiUser();
    actingAs($user);
    get('/api-admin/api-tokens', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertOk();

    ApiPlugin::get(alfakit()->getPanel('api-admin'))?->canManageTokens(static fn (ApiUser $user): bool => (bool) $user->getAttribute('api_admin'));

    get('/api-admin/api-tokens', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertForbidden();
    postJson('/api-admin/_alfakit/actions', [
        'token' => (new SchemaContext('api-admin', ApiTokens::class))->toToken(),
        'component' => 'page',
        'action' => 'createToken',
        'data' => ['name' => 'x', 'expires_in' => '30', 'access' => 'all'],
    ])->assertForbidden();

    expect(ApiToken::query()->count())->toBe(0);
});

it('does not register pages that are turned off', function (): void {
    $gamma = apiTeam('Gamma');
    $user = apiUser();
    $user->teams()->attach($gamma);
    actingAs($user);

    get('/api-tt/gamma/api-tokens')->assertNotFound();
    get('/api-tt/gamma/api-docs')->assertNotFound();
});

it('renders the API reference from the OpenAPI document', function (): void {
    actingAs(apiUser());

    $page = apiPageProps('/api-admin/api-docs');
    $content = (string) json_encode($page);

    expect($content)->toContain('http:\/\/localhost\/api\/v1')
        ->toContain('\/articles\/{record}')
        ->toContain('articles:bulk:publish')
        ->toContain('Blog articles.')
        ->toContain('\/notes');

    $document = get('/api-admin/api-docs/openapi.json')->assertOk()->json();

    expect($document['openapi'])->toBe('3.1.0')->and($document['paths'])->toHaveKey('/articles');
});

it('requires a signed-in user for the API reference', function (): void {
    get('/api-admin/api-docs/openapi.json')->assertRedirect();
});

it('renders the API reference of a tenancy panel with the tenant segment', function (): void {
    $alpha = apiTeam('Alpha');
    $user = apiUser();
    $user->teams()->attach($alpha);
    actingAs($user);

    expect((string) json_encode(apiPageProps('/api-team/alpha/api-docs')))->toContain('\/{tenant}\/articles')->toContain('http:\/\/localhost\/api\/team\/v1');
});

it('only documents resources the reader may list', function (): void {
    Illuminate\Support\Facades\Gate::policy(AlfaSix\AlfakitApi\Tests\Fixtures\Models\Note::class, ApiDenyAllPolicy::class);
    actingAs(apiUser());

    $document = get('/api-admin/api-docs/openapi.json')->assertOk()->json();

    expect($document['paths'])->toHaveKey('/articles')->not->toHaveKey('/notes');
});

class ApiDenyAllPolicy
{
    public function viewAny(mixed $user): bool
    {
        return false;
    }
}
