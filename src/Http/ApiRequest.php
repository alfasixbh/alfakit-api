<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http;

use AlfaSix\Alfakit\Panel;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Contracts\AccessToken;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The API state of the current request: the panel, its plugin and the token.
 */
final class ApiRequest
{
    public const ATTRIBUTE = 'alfakit_api';

    public const TOKEN_ATTRIBUTE = 'alfakit_api.token';

    public const RESOURCE_PARAMETER = '_alfakit_api_resource';

    public const PANEL_PARAMETER = '_alfakit_api_panel';

    public static function isApiRequest(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    public static function token(Request $request): ?AccessToken
    {
        $token = $request->attributes->get(self::TOKEN_ATTRIBUTE);

        return $token instanceof AccessToken ? $token : null;
    }

    public static function panel(Request $request): Panel
    {
        $id = $request->route()?->parameter(self::PANEL_PARAMETER);

        return alfakit()->getPanel(is_string($id) ? $id : null);
    }

    public static function plugin(Request $request): ApiPlugin
    {
        return ApiPlugin::get(self::panel($request)) ?? throw new RuntimeException('The panel of this API route has no API plugin.');
    }
}
