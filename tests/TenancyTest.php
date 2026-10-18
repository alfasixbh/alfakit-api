<?php

declare(strict_types=1);

use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;

use function AlfaSix\AlfakitApi\Tests\api;
use function AlfaSix\AlfakitApi\Tests\apiTeam;
use function AlfaSix\AlfakitApi\Tests\apiToken;
use function AlfaSix\AlfakitApi\Tests\apiUser;
use function AlfaSix\AlfakitApi\Tests\article;

uses(AlfaSix\AlfakitApi\Tests\TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * Alpha and Beta; Ada belongs to both, Bob to Beta only.
 *
 * @return array{alpha: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, beta: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, ada: AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser, bob: AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser}
 */
function apiTeams(): array
{
    $alpha = apiTeam('Alpha');
    $beta = apiTeam('Beta');
    $ada = apiUser('Ada', admin: true);
    $bob = apiUser('Bob', admin: true);
    $ada->teams()->attach([$alpha->getKey(), $beta->getKey()]);
    $bob->teams()->attach($beta);

    return compact('alpha', 'beta', 'ada', 'bob');
}

it('only lists, shows and changes the current tenant\'s records', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'ada' => $ada] = apiTeams();
    $mine = article(['title' => 'Alpha article', 'team_id' => $alpha->getKey()]);
    $theirs = article(['title' => 'Beta article', 'team_id' => $beta->getKey()]);
    $token = apiToken($ada, panel: 'api-team', tenant: $alpha);

    api('GET', '/api/team/v1/alpha/articles', $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Alpha article');
    api('GET', '/api/team/v1/alpha/articles/'.$mine->getKey(), $token)->assertOk();
    api('GET', '/api/team/v1/alpha/articles/'.$theirs->getKey(), $token)->assertNotFound();
    api('PATCH', '/api/team/v1/alpha/articles/'.$theirs->getKey(), $token, ['title' => 'Stolen'])->assertNotFound();
    api('DELETE', '/api/team/v1/alpha/articles/'.$theirs->getKey(), $token)->assertNotFound();
    api('POST', '/api/team/v1/alpha/articles/bulk/publish', $token, ['ids' => [$theirs->getKey()]])->assertNotFound();

    $created = api('POST', '/api/team/v1/alpha/articles', $token, ['title' => 'New in Alpha'])->assertCreated();

    expect(Article::query()->findOrFail($created->json('data.id'))->getAttribute('team_id'))->toBe($alpha->getKey())
        ->and($theirs->refresh()->getAttribute('title'))->toBe('Beta article');
});

it('binds tokens to the tenant they were created for', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'ada' => $ada, 'bob' => $bob] = apiTeams();

    // Ada belongs to Beta too, but this token was created in Alpha.
    api('GET', '/api/team/v1/beta/articles', apiToken($ada, panel: 'api-team', tenant: $alpha))
        ->assertForbidden()
        ->assertJsonPath('detail', __('alfakit-api::api.problems.wrong_tenant'));

    // Bob doesn't belong to Alpha at all.
    api('GET', '/api/team/v1/alpha/articles', apiToken($bob, panel: 'api-team', tenant: $alpha))->assertNotFound();
    api('GET', '/api/team/v1/nowhere/articles', apiToken($ada, panel: 'api-team', tenant: $alpha))->assertNotFound();

    // A token without a tenant doesn't work on a tenancy panel.
    api('GET', '/api/team/v1/alpha/articles', apiToken($ada, panel: 'api-team'))->assertForbidden();
});

it('stops a token when its user leaves the tenant', function (): void {
    ['beta' => $beta, 'bob' => $bob] = apiTeams();
    $token = apiToken($bob, panel: 'api-team', tenant: $beta);

    api('GET', '/api/team/v1/beta/articles', $token)->assertOk();

    $bob->teams()->detach($beta);

    api('GET', '/api/team/v1/beta/articles', $token)->assertNotFound();
});

it('takes the tenant from the token with tenantFromToken()', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'ada' => $ada] = apiTeams();
    article(['title' => 'Alpha article', 'team_id' => $alpha->getKey()]);
    article(['title' => 'Beta article', 'team_id' => $beta->getKey()]);

    api('GET', '/api/tt/v1/articles', apiToken($ada, panel: 'api-tt', tenant: $beta))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Beta article');
    api('GET', '/api/tt/v1/articles', apiToken($ada, panel: 'api-tt'))->assertNotFound();
});
