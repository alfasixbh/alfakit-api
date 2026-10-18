<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\AlfakitApi\Bridge\ApiCreateRecord;
use AlfaSix\AlfakitApi\Bridge\ApiEditRecord;
use AlfaSix\AlfakitApi\Bridge\ApiListRecords;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the resource's own List, Create and Edit pages (or stand-ins when it has none)
 * the way internal endpoints do, so the API uses the same table, form, hooks
 * (`mutateFormDataBeforeCreate()`, `handleRecordCreation()`, `afterSave()`…) and tenancy
 * logic as the panel.
 *
 * @internal
 */
final class ResourcePages
{
    /**
     * @param  class-string<resource>  $resource
     */
    public static function list(Panel $panel, string $resource): ListRecords
    {
        $class = self::pageClass($resource, 'index', ListRecords::class);

        if ($class === null) {
            ApiListRecords::usingResource($resource);
            $class = ApiListRecords::class;
        }

        return $class::fromContext(new SchemaContext($panel->getId(), $class));
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public static function create(Panel $panel, string $resource): CreateRecord
    {
        $class = self::pageClass($resource, 'create', CreateRecord::class);

        if ($class === null) {
            ApiCreateRecord::usingResource($resource);
            $class = ApiCreateRecord::class;
        }

        return $class::fromContext(new SchemaContext($panel->getId(), $class));
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public static function edit(Panel $panel, string $resource, Model $record): EditRecord
    {
        $class = self::pageClass($resource, 'edit', EditRecord::class);

        if ($class === null) {
            ApiEditRecord::usingResource($resource);
            $class = ApiEditRecord::class;
        }

        // `mount()` receives the record itself, so it is not looked up again.
        return $class::fromContext(new SchemaContext($panel->getId(), $class, ['record' => $record]));
    }

    /**
     * Call a protected method of a page (its lifecycle hooks are protected).
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function call(object $page, string $method, array $arguments = []): mixed
    {
        return (fn (): mixed => $this->{$method}(...$arguments))->call($page);
    }

    /**
     * @template TPage of object
     *
     * @param  class-string<resource>  $resource
     * @param  class-string<TPage>  $base
     * @return class-string<TPage>|null
     */
    private static function pageClass(string $resource, string $name, string $base): ?string
    {
        $registration = $resource::getPages()[$name] ?? null;

        if ($registration === null) {
            return null;
        }

        $class = $registration->page;

        return is_a($class, $base, true) ? $class : null;
    }
}
