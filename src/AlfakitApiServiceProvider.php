<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi;

use AlfaSix\AlfakitApi\Support\ApiRoutes;
use AlfaSix\AlfakitApi\Tokens\DatabaseTokenRepository;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AlfakitApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseTokenRepository::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-api');

        // After AlfaKit has registered the panels' routes (its own `booted` callback).
        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                return;
            }

            ApiRoutes::register($this->app->make(Router::class));
        });

        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-api-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-api'),
            ], 'alfakit-api-translations');
        }
    }
}
