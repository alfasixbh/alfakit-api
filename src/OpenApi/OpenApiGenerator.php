<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\OpenApi;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Support\ListQuery;
use AlfaSix\AlfakitApi\Support\RecordSerializer;
use AlfaSix\AlfakitApi\Tokens\Abilities;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Builds the OpenAPI 3.1 document of a panel's API from its exposed resources: paths for
 * every registered operation, request bodies from the forms, response schemas from the
 * field whitelists, list parameters from the tables, and the shared error responses.
 */
class OpenApiGenerator
{
    public const OPENAPI_VERSION = '3.1.0';

    final public function __construct(protected Panel $panel, protected ApiPlugin $plugin) {}

    public static function make(Panel $panel, ApiPlugin $plugin): static
    {
        return new static($panel, $plugin);
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];
        $schemas = $this->sharedSchemas();
        $tags = [];

        foreach ($this->getDocumentedResources() as $slug => $api) {
            $name = $this->schemaName($api);
            $model = $this->newModel($api);
            $form = $this->form($api, 'create');

            $schemas[$name] = $this->outputSchema($api, $model);

            if ($api->hasOperation('store') && $form !== null) {
                $schemas[$name.'CreateInput'] = SchemaInference::input($form, $api->getWritableFields(), forCreate: true);
            }

            if ($api->hasOperation('update') && ($edit = $this->form($api, 'edit')) !== null) {
                $schemas[$name.'UpdateInput'] = SchemaInference::input($edit, $api->getWritableFields(), forCreate: false);
            }

            $tags[] = array_filter(['name' => $api->getLabel(), 'description' => $api->getDescription()]);

            foreach ($this->resourcePaths($slug, $api, $name, $form !== null) as $path => $operations) {
                $paths[$path] = [...($paths[$path] ?? []), ...$operations];
            }
        }

        $paths['/token'] = ['get' => [
            'operationId' => 'getToken',
            'summary' => (string) __('alfakit-api::api.openapi.token_summary'),
            'tags' => [(string) __('alfakit-api::api.openapi.tokens_tag')],
            'responses' => [
                '200' => ['description' => (string) __('alfakit-api::api.openapi.ok'), 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Token']]]]]],
                '401' => ['$ref' => '#/components/responses/Unauthenticated'],
                '429' => ['$ref' => '#/components/responses/TooManyRequests'],
            ],
        ]];

        return [
            'openapi' => self::OPENAPI_VERSION,
            'info' => array_filter([
                'title' => $this->plugin->getTitle($this->panel),
                'version' => $this->plugin->getVersion(),
                'description' => $this->plugin->getDescription() ?? (string) __('alfakit-api::api.openapi.description'),
            ]),
            'servers' => [['url' => $this->serverUrl()]],
            'security' => [['bearerAuth' => []]],
            'tags' => [...$tags, ['name' => (string) __('alfakit-api::api.openapi.tokens_tag')]],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => (string) __('alfakit-api::api.openapi.bearer_description'),
                    ],
                ],
                'schemas' => $schemas,
                'parameters' => $this->sharedParameters(),
                'responses' => $this->sharedResponses(),
            ],
        ];
    }

    /**
     * The exposed resources the reader may list (all of them for anonymous readers of a
     * public document).
     *
     * @return array<string, ResourceApi>
     */
    public function getDocumentedResources(): array
    {
        $resources = $this->plugin->getResourceApis($this->panel);

        if ($this->panel->auth()->user() === null) {
            return $resources;
        }

        return array_filter($resources, static fn (ResourceApi $api): bool => $api->getResource()::canViewAny());
    }

    public function serverUrl(): string
    {
        $domain = $this->plugin->getDomain();
        $base = $this->plugin->getBasePath($this->panel);

        if ($domain !== null) {
            return (request()->isSecure() ? 'https' : 'http').'://'.$domain.'/'.$base;
        }

        return rtrim(url('/'), '/').'/'.$base;
    }

    /**
     * The path of a resource's collection, with the tenant segment on tenancy panels.
     */
    public function collectionPath(string $slug): string
    {
        return ($this->hasTenantSegment() ? '/{tenant}' : '').'/'.$slug;
    }

    public function hasTenantSegment(): bool
    {
        return $this->panel->hasTenancy() && ! $this->plugin->isTenantFromToken();
    }

    public function schemaName(ResourceApi $api): string
    {
        return Str::studly(Str::singular(str_replace(['/', '-'], '_', $api->getSlug())));
    }

    // ---------------------------------------------------------------- Paths

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function resourcePaths(string $slug, ResourceApi $api, string $name, bool $hasForm): array
    {
        $collection = $this->collectionPath($slug);
        $item = $collection.'/{record}';
        $tag = $api->getLabel();
        $label = $api->getModelLabel();
        $plural = Str::lower($api->getLabel());
        $tenant = $this->hasTenantSegment() ? [['$ref' => '#/components/parameters/Tenant']] : [];
        $recordParameter = [['name' => 'record', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.record_parameter', ['label' => $label])]];
        $includes = array_keys($api->getIncludes());
        $fields = RecordSerializer::for($this->panel, $this->plugin, $api)->getFieldNames();
        $single = ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$name]], 'required' => ['data']];
        $paths = [];

        $readParameters = array_values(array_filter([
            ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.fields_parameter', ['fields' => implode(', ', $fields)])],
            $includes === [] ? null : ['name' => 'include', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.include_parameter', ['relations' => implode(', ', $includes)])],
        ]));

        if ($api->hasOperation('index')) {
            $paths[$collection]['get'] = [
                'operationId' => 'list'.Str::studly($slug),
                'summary' => (string) __('alfakit-api::api.openapi.index_summary', ['label' => $plural]),
                'tags' => [$tag],
                'x-ability' => Abilities::for($slug, Abilities::READ),
                'parameters' => [...$tenant, ...$this->listParameters($api), ...$readParameters],
                'responses' => $this->responses([
                    '200' => ['description' => (string) __('alfakit-api::api.openapi.ok'), 'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => [
                            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$name]],
                            'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
                            'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
                        ],
                        'required' => ['data', 'meta', 'links'],
                    ]]]],
                ], [400, 401, 403, 429]),
            ];
        }

        if ($api->hasOperation('store') && $hasForm) {
            $paths[$collection]['post'] = [
                'operationId' => 'create'.Str::studly(Str::singular($slug)),
                'summary' => (string) __('alfakit-api::api.openapi.store_summary', ['label' => Str::lower($label)]),
                'tags' => [$tag],
                'x-ability' => Abilities::for($slug, Abilities::CREATE),
                'parameters' => [...$tenant, ['$ref' => '#/components/parameters/IdempotencyKey']],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$name.'CreateInput']]]],
                'responses' => $this->responses([
                    '201' => ['description' => (string) __('alfakit-api::api.openapi.created'), 'headers' => ['Location' => ['schema' => ['type' => 'string', 'format' => 'uri']], 'ETag' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $single]]],
                ], [400, 401, 403, 409, 422, 429]),
            ];
        }

        if ($api->hasOperation('show')) {
            $paths[$item]['get'] = [
                'operationId' => 'get'.Str::studly(Str::singular($slug)),
                'summary' => (string) __('alfakit-api::api.openapi.show_summary', ['label' => Str::lower($label)]),
                'tags' => [$tag],
                'x-ability' => Abilities::for($slug, Abilities::READ),
                'parameters' => [...$tenant, ...$recordParameter, ...$readParameters, ['name' => 'If-None-Match', 'in' => 'header', 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.if_none_match')]],
                'responses' => $this->responses([
                    '200' => ['description' => (string) __('alfakit-api::api.openapi.ok'), 'headers' => ['ETag' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $single]]],
                    '304' => ['description' => (string) __('alfakit-api::api.openapi.not_modified')],
                ], [400, 401, 403, 404, 429]),
            ];
        }

        if ($api->hasOperation('update') && $hasForm) {
            $operation = [
                'summary' => (string) __('alfakit-api::api.openapi.update_summary', ['label' => Str::lower($label)]),
                'tags' => [$tag],
                'x-ability' => Abilities::for($slug, Abilities::UPDATE),
                'parameters' => [...$tenant, ...$recordParameter, ['$ref' => '#/components/parameters/IfMatch']],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$name.'UpdateInput']]]],
                'responses' => $this->responses([
                    '200' => ['description' => (string) __('alfakit-api::api.openapi.ok'), 'headers' => ['ETag' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $single]]],
                ], [400, 401, 403, 404, 412, 422, 429]),
            ];

            $paths[$item]['patch'] = ['operationId' => 'update'.Str::studly(Str::singular($slug)), ...$operation];
            $paths[$item]['put'] = ['operationId' => 'replace'.Str::studly(Str::singular($slug)), ...$operation];
        }

        if ($api->hasOperation('destroy')) {
            $paths[$item]['delete'] = [
                'operationId' => 'delete'.Str::studly(Str::singular($slug)),
                'summary' => (string) __('alfakit-api::api.openapi.destroy_summary', ['label' => Str::lower($label)]),
                'tags' => [$tag],
                'x-ability' => Abilities::for($slug, Abilities::DELETE),
                'parameters' => [...$tenant, ...$recordParameter, ['$ref' => '#/components/parameters/IfMatch']],
                'responses' => $this->responses([
                    '204' => ['description' => (string) __('alfakit-api::api.openapi.deleted')],
                ], [401, 403, 404, 412, 429]),
            ];
        }

        foreach ($api->getBulkActions() as $action) {
            $paths[$collection.'/bulk/'.$action]['post'] = [
                'operationId' => 'bulk'.Str::studly($action).Str::studly($slug),
                'summary' => (string) __('alfakit-api::api.openapi.bulk_summary', ['action' => $action, 'label' => $plural]),
                'tags' => [$tag],
                'x-ability' => Abilities::bulk($slug, $action),
                'parameters' => $tenant,
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ids' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'minItems' => 1, 'maxItems' => \AlfaSix\AlfakitApi\Http\Controllers\ResourceController::MAX_BULK_RECORDS],
                        'data' => ['type' => 'object', 'description' => (string) __('alfakit-api::api.openapi.bulk_data')],
                    ],
                    'required' => ['ids'],
                ]]]],
                'responses' => $this->responses([
                    '200' => ['description' => (string) __('alfakit-api::api.openapi.ok'), 'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'properties' => ['data' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string'], 'count' => ['type' => 'integer']]]],
                    ]]]],
                ], [401, 403, 404, 422, 429]),
            ];
        }

        return $paths;
    }

    /**
     * `page`, `per_page`, `sort`, `search` and one `filter[…]` per table filter.
     *
     * @return list<array<string, mixed>>
     */
    protected function listParameters(ResourceApi $api): array
    {
        $table = $this->table($api);
        $sortable = ListQuery::sortableColumns($table);
        $parameters = [
            ['$ref' => '#/components/parameters/Page'],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $api->getMaxPerPage() ?? $this->plugin->getMaxPerPage(), 'default' => $api->getDefaultPerPage() ?? $this->plugin->getDefaultPerPage()]],
        ];

        if ($sortable !== []) {
            $parameters[] = ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => [...$sortable, ...array_map(static fn (string $column): string => '-'.$column, $sortable)]], 'description' => (string) __('alfakit-api::api.openapi.sort_parameter')];
        }

        if ($table !== null && ListQuery::isSearchable($table)) {
            $parameters[] = ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.search_parameter')];
        }

        foreach ($table?->getFilters() ?? [] as $name => $filter) {
            $schema = match (true) {
                $filter instanceof TernaryFilter, $filter instanceof Filter => ['type' => 'boolean'],
                $filter instanceof SelectFilter && $filter->isMultiple() => ['type' => 'string', 'description' => (string) __('alfakit-api::api.openapi.comma_separated')],
                default => ['type' => 'string'],
            };

            $parameters[] = ['name' => 'filter['.$name.']', 'in' => 'query', 'schema' => $schema, 'description' => self::safe(static fn (): string => $filter->getLabel()) ?? $name];
        }

        return $parameters;
    }

    // ---------------------------------------------------------------- Schemas

    /**
     * @return array<string, mixed>
     */
    protected function outputSchema(ResourceApi $api, Model $model): array
    {
        $types = SchemaInference::columnTypes($model);
        $properties = [];

        foreach (RecordSerializer::for($this->panel, $this->plugin, $api)->getFields() as $name => $source) {
            $properties[$name] = is_string($source) ? SchemaInference::output($model, $source, $types) : new stdClass;
        }

        foreach ($api->getIncludes() as $relation => $fields) {
            $properties[$relation] = ['description' => (string) __('alfakit-api::api.openapi.included', ['relation' => $relation]), 'type' => ['object', 'array', 'null']];
        }

        return ['type' => 'object', 'properties' => $properties === [] ? new stdClass : $properties];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedSchemas(): array
    {
        return [
            'Problem' => [
                'type' => 'object',
                'description' => (string) __('alfakit-api::api.openapi.problem_description'),
                'properties' => [
                    'type' => ['type' => 'string', 'format' => 'uri-reference'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string', 'format' => 'uri-reference'],
                ],
                'required' => ['type', 'title', 'status'],
            ],
            'ValidationProblem' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/Problem'],
                    ['type' => 'object', 'properties' => ['errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]], 'required' => ['errors']],
                ],
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'total' => ['type' => 'integer'],
                    'last_page' => ['type' => 'integer'],
                    'from' => ['type' => ['integer', 'null']],
                    'to' => ['type' => ['integer', 'null']],
                ],
            ],
            'PaginationLinks' => [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string', 'format' => 'uri'],
                    'last' => ['type' => 'string', 'format' => 'uri'],
                    'prev' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'next' => ['type' => ['string', 'null'], 'format' => 'uri'],
                ],
            ],
            'Token' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'last_used_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'user' => ['type' => ['object', 'null']],
                    'tenant' => ['type' => ['object', 'null']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedParameters(): array
    {
        $parameters = [
            'Page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string', 'maxLength' => 255], 'description' => (string) __('alfakit-api::api.openapi.idempotency_key', ['hours' => max(1, intdiv($this->plugin->getIdempotencyTtl(), 60))])],
            'IfMatch' => ['name' => 'If-Match', 'in' => 'header', 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.if_match')],
        ];

        if ($this->hasTenantSegment()) {
            $parameters['Tenant'] = ['name' => 'tenant', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => (string) __('alfakit-api::api.openapi.tenant_parameter')];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedResponses(): array
    {
        $problem = static fn (string $description, string $schema = 'Problem'): array => [
            'description' => $description,
            'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ];

        return [
            'BadRequest' => $problem((string) __('alfakit-api::api.openapi.responses.bad_request')),
            'Unauthenticated' => $problem((string) __('alfakit-api::api.openapi.responses.unauthenticated')),
            'Forbidden' => $problem((string) __('alfakit-api::api.openapi.responses.forbidden')),
            'NotFound' => $problem((string) __('alfakit-api::api.openapi.responses.not_found')),
            'Conflict' => $problem((string) __('alfakit-api::api.openapi.responses.conflict')),
            'PreconditionFailed' => $problem((string) __('alfakit-api::api.openapi.responses.precondition_failed')),
            'ValidationFailed' => $problem((string) __('alfakit-api::api.openapi.responses.validation'), 'ValidationProblem'),
            'TooManyRequests' => [
                ...$problem((string) __('alfakit-api::api.openapi.responses.too_many_requests')),
                'headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]],
            ],
        ];
    }

    /**
     * Success responses plus the shared error responses (keys are status codes, so the
     * arrays are added, never spread: spreading would renumber them).
     *
     * @param  array<int|string, mixed>  $success
     * @param  list<int>  $errors
     * @return array<int|string, mixed>
     */
    protected function responses(array $success, array $errors): array
    {
        return $success + $this->errorResponses($errors);
    }

    /**
     * @param  list<int>  $statuses
     * @return array<int|string, array<string, string>>
     */
    protected function errorResponses(array $statuses): array
    {
        $map = [400 => 'BadRequest', 401 => 'Unauthenticated', 403 => 'Forbidden', 404 => 'NotFound', 409 => 'Conflict', 412 => 'PreconditionFailed', 422 => 'ValidationFailed', 429 => 'TooManyRequests'];
        $responses = [];

        foreach ($statuses as $status) {
            if (isset($map[$status])) {
                $responses[(string) $status] = ['$ref' => '#/components/responses/'.$map[$status]];
            }
        }

        return $responses;
    }

    // ---------------------------------------------------------------- Helpers

    protected function newModel(ResourceApi $api): Model
    {
        $model = $api->getResource()::getModel();

        return new $model;
    }

    protected function form(ResourceApi $api, string $operation): ?Schema
    {
        return self::safe(function () use ($api, $operation): ?Schema {
            $resource = $api->getResource();
            $form = $resource::form(Schema::make()->model($resource::getModel())->operation($operation));

            if ($form->getComponents(withHidden: true) === []) {
                return null;
            }

            return $form->fill();
        });
    }

    protected function table(ResourceApi $api): ?Table
    {
        return self::safe(function () use ($api): Table {
            $resource = $api->getResource();

            return $resource::table(Table::make()->query(static fn () => $resource::getEloquentQuery()));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    protected static function safe(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
