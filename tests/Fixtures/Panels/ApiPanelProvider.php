<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\NoteResource;

class ApiPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api-admin')
            ->path('api-admin')
            ->login()
            ->middleware(['web'])
            ->resources([ArticleResource::class, NoteResource::class])
            ->plugin(
                ApiPlugin::make()
                    ->prefix('api')
                    ->resources([
                        ResourceApi::make(ArticleResource::class)
                            ->includes(['author' => ['id', 'name', 'password']])
                            ->hiddenFields(['attachment'])
                            ->bulkActions(['publish', 'delete'])
                            ->description('Blog articles.'),
                    ])
                    ->rateLimit(static fn (AccessToken $token): int => $token->getTokenName() === 'limited' ? 2 : 1000),
            );
    }
}
