<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;

class ApiTenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api-team')
            ->path('api-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([ArticleResource::class])
            ->plugin(ApiPlugin::make()->prefix('api/team')->resources([ArticleResource::class]));
    }
}
