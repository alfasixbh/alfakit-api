<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitApi\ApiPlugin;

/**
 * The plugin right after `alfakit:add api`: registered, nothing exposed yet.
 */
class ApiEmptyPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api-empty')
            ->path('api-empty')
            ->login()
            ->middleware(['web'])
            ->plugin(ApiPlugin::make()->prefix('api/empty'));
    }
}
