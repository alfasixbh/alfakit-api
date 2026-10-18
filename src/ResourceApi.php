<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * How one resource is exposed by the API: its endpoints, the fields it returns, the
 * relations clients may include and the bulk actions they may run.
 *
 * ```php
 * ApiPlugin::make()->resources([
 *     ResourceApi::make(PostResource::class)
 *         ->fields(['id', 'title', 'status', 'published_at', 'excerpt' => fn (Post $record): string => Str::limit($record->body, 120)])
 *         ->includes(['author' => ['id', 'name'], 'tags' => ['id', 'name']])
 *         ->bulkActions(['publish'])
 *         ->operations(['index', 'show', 'store', 'update']),
 * ]);
 * ```
 *
 * Or on the resource itself (it is then exposed without being listed in the plugin):
 *
 * ```php
 * class PostResource extends Resource implements ExposesApi
 * {
 *     public static function api(ResourceApi $api): ResourceApi
 *     {
 *         return $api->fields(['id', 'title', 'status']);
 *     }
 * }
 * ```
 */
class ResourceApi
{
    use EvaluatesClosures;

    public const OPERATIONS = ['index', 'show', 'store', 'update', 'destroy'];

    /** @var array<int|string, string|Closure>|null */
    protected ?array $fields = null;

    /** @var list<string> */
    protected array $hiddenFields = [];

    /** @var list<string>|null */
    protected ?array $writableFields = null;

    /** @var array<string, list<string>|null> */
    protected array $includes = [];

    /** @var list<string> */
    protected array $operations = self::OPERATIONS;

    /** @var list<string> */
    protected array $bulkActions = [];

    protected ?string $slug = null;

    protected string|Closure|null $description = null;

    protected ?Closure $modifyQueryUsing = null;

    protected ?int $defaultPerPage = null;

    protected ?int $maxPerPage = null;

    /**
     * @param  class-string<resource>  $resource
     */
    final public function __construct(protected string $resource)
    {
        if (! is_a($resource, Resource::class, true)) {
            throw new InvalidArgumentException("[{$resource}] is not an AlfaKit resource.");
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    public static function make(string $resource): static
    {
        return new static($resource);
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'api';
    }

    /**
     * @return class-string<resource>
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    // ---------------------------------------------------------------- Endpoints

    /**
     * The URL segment (`/api/v1/{slug}`); the resource's slug by default.
     */
    public function slug(?string $slug): static
    {
        $this->slug = $slug === null ? null : trim($slug, '/');

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug ?? Str::of($this->resource::getSlug())->replace('/', '-')->toString();
    }

    /**
     * The endpoints to register: any of `index`, `show`, `store`, `update` and `destroy`.
     *
     * @param  array<int, string>  $operations
     */
    public function operations(array $operations): static
    {
        $unknown = array_diff($operations, self::OPERATIONS);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown API operations: '.implode(', ', $unknown).'.');
        }

        $this->operations = array_values(array_intersect(self::OPERATIONS, $operations));

        return $this;
    }

    /**
     * Only `index` and `show`.
     */
    public function readOnly(bool $condition = true): static
    {
        if ($condition) {
            $this->operations = array_values(array_intersect($this->operations, ['index', 'show']));
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function hasOperation(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /**
     * Bulk actions of the resource's table that the API may run, by name
     * (`POST /api/v1/posts/bulk/{name}`). None by default.
     *
     * @param  array<int, string>  $actions
     */
    public function bulkActions(array $actions): static
    {
        $this->bulkActions = array_values(array_unique(array_filter($actions, static fn (mixed $name): bool => is_string($name) && $name !== '')));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getBulkActions(): array
    {
        return $this->bulkActions;
    }

    public function hasBulkAction(string $name): bool
    {
        return in_array($name, $this->bulkActions, true);
    }

    // ---------------------------------------------------------------- Output

    /**
     * The fields every record returns. Strings are attributes (with the model's casts);
     * `'name' => fn (Model $record): mixed` adds a computed field. Attributes in the
     * model's `$hidden` are never returned, even when listed.
     *
     * By default: the primary key, the resource's form fields and table columns (without
     * relationship columns) and the timestamps.
     *
     * @param  array<int|string, string|Closure>  $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @return array<int|string, string|Closure>|null
     */
    public function getFields(): ?array
    {
        return $this->fields;
    }

    /**
     * Fields never returned (on top of the model's `$hidden`).
     *
     * @param  array<int, string>  $fields
     */
    public function hiddenFields(array $fields): static
    {
        $this->hiddenFields = array_values($fields);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getHiddenFields(): array
    {
        return $this->hiddenFields;
    }

    /**
     * Limit the form fields the API accepts on `store` and `update` (all visible,
     * enabled form fields by default). Other fields keep their default or current value.
     *
     * @param  array<int, string>|null  $fields
     */
    public function writableFields(?array $fields): static
    {
        $this->writableFields = $fields === null ? null : array_values($fields);

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getWritableFields(): ?array
    {
        return $this->writableFields;
    }

    /**
     * Relations clients may embed with `?include=author,tags`. `'author' => ['id', 'name']`
     * returns those fields; `'author'` alone returns the fields of the related model's
     * API resource (or only its key when the related model has none).
     *
     * @param  array<int|string, string|array<int, string>>  $includes
     */
    public function includes(array $includes): static
    {
        $this->includes = [];

        foreach ($includes as $relation => $fields) {
            if (is_int($relation)) {
                if (is_string($fields)) {
                    $this->includes[$fields] = null;
                }

                continue;
            }

            $this->includes[$relation] = is_array($fields) ? array_values(array_map(strval(...), $fields)) : null;
        }

        return $this;
    }

    /**
     * @return array<string, list<string>|null>
     */
    public function getIncludes(): array
    {
        return $this->includes;
    }

    // ---------------------------------------------------------------- Listing

    /**
     * Narrow the API's query further than the resource's `getEloquentQuery()`:
     * `fn (Builder $query) => $query->where('is_public', true)`.
     */
    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function modifyQuery(Builder $query): Builder
    {
        if ($this->modifyQueryUsing === null) {
            return $query;
        }

        $result = $this->evaluate($this->modifyQueryUsing, ['query' => $query], [Builder::class => $query]);

        return $result instanceof Builder ? $result : $query;
    }

    public function defaultPerPage(?int $perPage): static
    {
        $this->defaultPerPage = $perPage;

        return $this;
    }

    public function getDefaultPerPage(): ?int
    {
        return $this->defaultPerPage;
    }

    public function maxPerPage(?int $perPage): static
    {
        $this->maxPerPage = $perPage;

        return $this;
    }

    public function getMaxPerPage(): ?int
    {
        return $this->maxPerPage;
    }

    // ---------------------------------------------------------------- Documentation

    /**
     * Shown in the OpenAPI document and the API reference page.
     */
    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return is_string($description) && $description !== '' ? $description : null;
    }

    public function getLabel(): string
    {
        return $this->resource::getTitleCasePluralModelLabel();
    }

    public function getModelLabel(): string
    {
        return $this->resource::getModelLabel();
    }
}
