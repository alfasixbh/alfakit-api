<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;
use DateTimeInterface;
use Illuminate\Testing\TestResponse;

function apiUser(string $name = 'Ada', bool $admin = false): ApiUser
{
    return ApiUser::query()->create(['name' => $name, 'email' => uniqid('api', true).'@example.com', 'password' => 'password', 'api_admin' => $admin]);
}

function apiTeam(string $name): Team
{
    return Team::query()->create(['name' => $name, 'slug' => strtolower($name)]);
}

/**
 * @param  list<string>  $abilities
 */
function apiToken(ApiUser $user, array $abilities = ['*'], string $panel = 'api-admin', ?Team $tenant = null, ?DateTimeInterface $expiresAt = null, string $name = 'Test token'): string
{
    $plugin = ApiPlugin::get(alfakit()->getPanel($panel));
    assert($plugin !== null);

    return $plugin->getTokenRepository()->create($user, $panel, $name, $abilities, $expiresAt, $tenant)->plainTextToken;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function article(array $attributes = []): Article
{
    return Article::query()->create(['title' => 'Hello world', 'status' => 'draft', ...$attributes]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $headers
 * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function api(string $method, string $uri, ?string $token, array $data = [], array $headers = []): TestResponse
{
    $headers = [...$headers, 'Accept' => 'application/json'];

    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return test()->json($method, $uri, $data, $headers);
}
