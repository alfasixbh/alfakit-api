<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitApi\AlfakitApiServiceProvider;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;
use AlfaSix\AlfakitApi\Tests\Fixtures\Panels\ApiEmptyPanelProvider;
use AlfaSix\AlfakitApi\Tests\Fixtures\Panels\ApiPanelProvider;
use AlfaSix\AlfakitApi\Tests\Fixtures\Panels\ApiTenantPanelProvider;
use AlfaSix\AlfakitApi\Tests\Fixtures\Panels\ApiTokenTenantPanelProvider;
use AlfaSix\AlfakitApi\Tests\Fixtures\Policies\ArticlePolicy;
use Illuminate\Support\Facades\Gate;

abstract class TestCase extends AlfakitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::policy(Article::class, ArticlePolicy::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitApiServiceProvider::class,
            ApiPanelProvider::class,
            ApiTenantPanelProvider::class,
            ApiTokenTenantPanelProvider::class,
            ApiEmptyPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', ApiUser::class);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/database/migrations');

        // `SanctumTokenRepository` (laravel/sanctum is a dev dependency).
        if (is_dir($sanctum = dirname(__DIR__, 4).'/vendor/laravel/sanctum/database/migrations')) {
            $this->loadMigrationsFrom($sanctum);
        }
    }
}
