<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Bridge;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Resource;

/**
 * Stands in for the EditRecord page of resources that have none (e.g. modal-only
 * resources), so the API runs the same form, hooks and tenancy logic. Never routed.
 *
 * @internal
 */
final class ApiEditRecord extends EditRecord
{
    protected static bool $isDiscovered = false;

    /** Its own storage (not the parent's), set before every use. */
    protected static string $resource = Resource::class;

    /**
     * @param  class-string<resource>  $resource
     */
    public static function usingResource(string $resource): void
    {
        self::$resource = $resource;
    }
}
