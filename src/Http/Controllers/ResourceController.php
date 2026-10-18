<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Controllers;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Http\ApiRequest;
use AlfaSix\AlfakitApi\Http\ProblemException;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Support\ETag;
use AlfaSix\AlfakitApi\Support\Idempotency;
use AlfaSix\AlfakitApi\Support\ListQuery;
use AlfaSix\AlfakitApi\Support\RecordSerializer;
use AlfaSix\AlfakitApi\Support\ResourcePages;
use AlfaSix\AlfakitApi\Tokens\Abilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The CRUD endpoints of one exposed resource. Every call checks the token's ability for
 * the operation and the resource's policy; queries go through the resource's (tenant
 * scoped) `getEloquentQuery()`; writes run the resource's own form validation and page
 * hooks.
 */
class ResourceController
{
    public const MAX_BULK_RECORDS = 1000;

    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // ---------------------------------------------------------------- Endpoints

    public function index(Request $request): JsonResponse
    {
        [$panel, $plugin, $api, $resource] = $this->context($request, Abilities::READ);

        if (! $resource::canViewAny()) {
            throw $this->forbidden();
        }

        $page = ResourcePages::list($panel, $resource);
        $table = $page->getTable();

        $list = ListQuery::fromRequest(
            $request,
            $table,
            $api->getDefaultPerPage() ?? $plugin->getDefaultPerPage(),
            $api->getMaxPerPage() ?? $plugin->getMaxPerPage(),
        );

        $serializer = RecordSerializer::for($panel, $plugin, $api);
        $fields = $this->sparseFields($request, $serializer);
        $includes = $this->includes($request, $api);

        if ($table !== null) {
            $table->state($list->toTableState());
            $query = $table->getFilteredSortedQuery() ?? $resource::getEloquentQuery();
        } else {
            $query = $resource::getEloquentQuery();
        }

        $query = $api->modifyQuery($query);

        if ($includes !== []) {
            $query->with($includes);
        }

        // Stable pages: the primary key breaks ties (and orders unsorted lists).
        if (! collect($query->getQuery()->orders ?? [])->contains(static fn (array $order): bool => ($order['column'] ?? null) === $query->getModel()->getQualifiedKeyName())) {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), $list->sortDirection === 'desc' ? 'desc' : 'asc');
        }

        $paginator = $query->paginate($list->perPage, ['*'], 'page', $list->page)->withQueryString();

        $data = [];

        foreach ($paginator->items() as $record) {
            $data[] = $serializer->serialize($record, $fields, $includes);
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url(max(1, $paginator->lastPage())),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ], 200, [], self::JSON_FLAGS);
    }

    public function show(Request $request): JsonResponse|Response
    {
        [$panel, $plugin, $api, $resource] = $this->context($request, Abilities::READ);

        $includes = $this->includes($request, $api);
        $model = $this->findRecord($request, $api, $this->routeParameter($request, 'record'), $includes);

        if (! $resource::canView($model)) {
            throw $this->forbidden();
        }

        $serializer = RecordSerializer::for($panel, $plugin, $api);
        $body = ['data' => $serializer->serialize($model, $this->sparseFields($request, $serializer), $includes)];
        $etag = ETag::for($body);
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (ETag::matchesIfNoneMatch($request, $etag)) {
            return new Response('', 304, $headers);
        }

        return new JsonResponse($body, 200, $headers, self::JSON_FLAGS);
    }

    public function store(Request $request): JsonResponse
    {
        [$panel, $plugin, $api, $resource] = $this->context($request, Abilities::CREATE);

        if (! $resource::canCreate()) {
            throw $this->forbidden();
        }

        $token = ApiRequest::token($request);
        $tenant = alfakit()->getTenant();
        $scope = implode('|', [$panel->getId(), $token?->getTokenId() ?? '', $tenant === null ? '' : $tenant->getMorphClass().':'.$tenant->getKey(), $api->getSlug()]);

        return Idempotency::run($request, $plugin, $scope, function () use ($request, $panel, $plugin, $api, $resource): JsonResponse {
            $page = ResourcePages::create($panel, $resource);
            $form = $page->getForm() ?? throw ProblemException::make(405, (string) __('alfakit-api::api.problems.no_form'), 'no-form');

            $form->applyClientState([...$form->getRawState(), ...$this->input($request, $form, $api)]);

            ResourcePages::call($page, 'callHook', ['beforeValidate']);
            $data = $form->validate();
            ResourcePages::call($page, 'callHook', ['afterValidate']);

            ResourcePages::call($page, 'callHook', ['beforeCreate', [$data]]);
            $data = ResourcePages::call($page, 'mutateFormDataBeforeCreate', [$data]);

            $record = DB::transaction(static function () use ($page, $form, $data): Model {
                $record = ResourcePages::call($page, 'handleRecordCreation', [$data]);

                if (! $record instanceof Model) {
                    throw ProblemException::make(500, (string) __('alfakit-api::api.problems.server_error'), 'server-error');
                }

                $form->record($record)->saveRelationships();

                return $record;
            });

            (function () use ($record): void {
                $this->createdRecord = $record;
            })->call($page);

            ResourcePages::call($page, 'callHook', ['afterCreate']);

            $record = $this->refresh($resource, $api, $record);
            $body = ['data' => RecordSerializer::for($panel, $plugin, $api)->serialize($record)];

            return new JsonResponse($body, 201, [
                'Location' => $this->recordUrl($request, $api, $resource, $record),
                'ETag' => ETag::for($body),
            ], self::JSON_FLAGS);
        });
    }

    public function update(Request $request): JsonResponse
    {
        [$panel, $plugin, $api, $resource] = $this->context($request, Abilities::UPDATE);

        $model = $this->findRecord($request, $api, $this->routeParameter($request, 'record'));

        if (! $resource::canEdit($model)) {
            throw $this->forbidden();
        }

        $serializer = RecordSerializer::for($panel, $plugin, $api);
        $this->checkPrecondition($request, ['data' => $serializer->serialize($model)]);

        $page = ResourcePages::edit($panel, $resource, $model);
        $form = $page->getForm() ?? throw ProblemException::make(405, (string) __('alfakit-api::api.problems.no_form'), 'no-form');

        // Fields the request leaves out keep the record's values (PUT behaves like PATCH).
        $form->applyClientState([...$form->getRawState(), ...$this->input($request, $form, $api)]);

        ResourcePages::call($page, 'callHook', ['beforeValidate']);
        $data = $form->validate();
        ResourcePages::call($page, 'callHook', ['afterValidate']);

        ResourcePages::call($page, 'callHook', ['beforeSave', [$data]]);
        $data = ResourcePages::call($page, 'mutateFormDataBeforeSave', [$data]);

        $updated = DB::transaction(static function () use ($page, $form, $model, $data): Model {
            $updated = ResourcePages::call($page, 'handleRecordUpdate', [$model, $data]);
            $updated = $updated instanceof Model ? $updated : $model;

            (function () use ($updated): void {
                $this->record = $updated;
            })->call($page);

            $form->record($updated)->saveRelationships();

            return $updated;
        });

        ResourcePages::call($page, 'callHook', ['afterSave']);

        $body = ['data' => $serializer->serialize($this->refresh($resource, $api, $updated))];

        return new JsonResponse($body, 200, ['ETag' => ETag::for($body)], self::JSON_FLAGS);
    }

    public function destroy(Request $request): Response
    {
        [$panel, $plugin, $api, $resource] = $this->context($request, Abilities::DELETE);

        $model = $this->findRecord($request, $api, $this->routeParameter($request, 'record'));

        if (! $resource::canDelete($model)) {
            throw $this->forbidden();
        }

        $this->checkPrecondition($request, ['data' => RecordSerializer::for($panel, $plugin, $api)->serialize($model)]);

        $model->delete();

        return new Response('', 204);
    }

    public function bulk(Request $request): JsonResponse
    {
        [$panel, , $api, $resource] = $this->context($request, null);
        $action = $this->routeParameter($request, 'action');

        if (! $api->hasBulkAction($action)) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
        }

        $this->ensureAbility($request, Abilities::bulk($api->getSlug(), $action));

        if (! $resource::canViewAny()) {
            throw $this->forbidden();
        }

        $ids = $request->input('ids');

        if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_BULK_RECORDS || ! array_is_list($ids) || array_filter($ids, static fn (mixed $id): bool => ! is_int($id) && ! is_string($id)) !== []) {
            throw ProblemException::make(422, (string) __('alfakit-api::api.problems.validation'), 'validation', ['errors' => ['ids' => [(string) __('alfakit-api::api.problems.invalid_ids', ['max' => self::MAX_BULK_RECORDS])]]]);
        }

        $data = $request->input('data', []);
        $data = is_array($data) ? $data : [];
        unset($data[Schema::READ_ONLY_STATE_KEY]);

        $page = ResourcePages::list($panel, $resource);
        $table = $page->getTable();

        try {
            $bulkAction = $page->resolveAction('table', $action, isBulk: true);
        } catch (ActionNotResolvableException) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
        }

        if (! $bulkAction instanceof BulkAction || $table === null) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
        }

        $query = $api->modifyQuery($table->getQuery() ?? $resource::getEloquentQuery())->whereKey($ids);
        $count = (clone $query)->count();

        $bulkAction->selectedRecordsQuery($query, static fn (Model|array $record): bool => $table->isRecordSelectable($record));
        $bulkAction->execute(data: $data);

        return new JsonResponse(['data' => ['action' => $action, 'count' => $count]], 200, [], self::JSON_FLAGS);
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * @return array{0: Panel, 1: ApiPlugin, 2: ResourceApi, 3: class-string<resource>}
     */
    protected function context(Request $request, ?string $operation): array
    {
        $panel = ApiRequest::panel($request);
        $plugin = ApiRequest::plugin($request);
        $slug = $request->route()?->parameter(ApiRequest::RESOURCE_PARAMETER);
        $api = is_string($slug) ? $plugin->getResourceApi($panel, $slug) : null;

        if ($api === null) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
        }

        if ($operation !== null) {
            $this->ensureAbility($request, Abilities::for($api->getSlug(), $operation));
        }

        return [$panel, $plugin, $api, $api->getResource()];
    }

    protected function routeParameter(Request $request, string $name): string
    {
        $value = $request->route()?->parameter($name);

        return is_scalar($value) ? (string) $value : '';
    }

    protected function ensureAbility(Request $request, string $ability): void
    {
        $token = ApiRequest::token($request);

        if ($token === null || ! $token->can($ability)) {
            throw ProblemException::make(403, (string) __('alfakit-api::api.problems.missing_ability', ['ability' => $ability]), 'insufficient-ability', ['ability' => $ability]);
        }
    }

    protected function forbidden(): ProblemException
    {
        return ProblemException::make(403, (string) __('alfakit-api::api.problems.forbidden'), 'forbidden');
    }

    /**
     * @param  list<string>  $includes
     */
    protected function findRecord(Request $request, ResourceApi $api, string $key, array $includes = []): Model
    {
        $resource = $api->getResource();
        $query = $api->modifyQuery($resource::getEloquentQuery());
        $keyName = $resource::getRecordRouteKeyName() ?? $query->getModel()->getRouteKeyName();

        if ($includes !== []) {
            $query->with($includes);
        }

        $record = $query->where($query->qualifyColumn($keyName), $key)->first();

        if (! $record instanceof Model) {
            throw ProblemException::make(404, (string) __('alfakit-api::api.problems.record_not_found'), 'not-found');
        }

        return $record;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function refresh(string $resource, ResourceApi $api, Model $record): Model
    {
        $fresh = $api->modifyQuery($resource::getEloquentQuery())->whereKey($record->getKey())->first();

        return $fresh instanceof Model ? $fresh : $record;
    }

    /**
     * `If-Match` on update and delete: 412 unless it names the record's current ETag.
     *
     * @param  array<string, mixed>  $representation
     */
    protected function checkPrecondition(Request $request, array $representation): void
    {
        if (! ETag::satisfiesIfMatch($request, ETag::for($representation))) {
            throw ProblemException::make(412, (string) __('alfakit-api::api.problems.precondition_failed'), 'precondition-failed');
        }
    }

    /**
     * The request body, limited to the top-level form fields the API accepts.
     *
     * @return array<string, mixed>
     */
    protected function input(Request $request, Schema $form, ResourceApi $api): array
    {
        $body = $request->isJson() ? json_decode($request->getContent() === '' ? '{}' : $request->getContent(), true) : $request->request->all();

        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw ProblemException::make(400, (string) __('alfakit-api::api.problems.invalid_body'), 'invalid-body');
        }

        $writable = [];

        foreach (array_keys($form->getFlatFields()) as $path) {
            $writable[] = explode('.', (string) $path)[0];
        }

        if (($only = $api->getWritableFields()) !== null) {
            $writable = array_intersect($writable, $only);
        }

        $input = array_intersect_key($body, array_flip(array_unique($writable)));
        unset($input[Schema::READ_ONLY_STATE_KEY]);

        return $input;
    }

    /**
     * `?fields=title,status`: a subset of the resource's fields.
     *
     * @return list<string>|null
     */
    protected function sparseFields(Request $request, RecordSerializer $serializer): ?array
    {
        $value = $request->query('fields');

        if ($value === null || $value === '') {
            return null;
        }

        $fields = is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $field): bool => $field !== '')) : null;
        $allowed = $serializer->getFieldNames();

        if ($fields === null || array_diff($fields, $allowed) !== []) {
            throw ListQuery::invalid('fields', (string) __('alfakit-api::api.problems.invalid_fields'), ['allowed' => $allowed]);
        }

        return $fields;
    }

    /**
     * `?include=author,tags`: whitelisted relations only.
     *
     * @return list<string>
     */
    protected function includes(Request $request, ResourceApi $api): array
    {
        $value = $request->query('include');

        if ($value === null || $value === '') {
            return [];
        }

        $includes = is_string($value) ? array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $include): bool => $include !== ''))) : null;
        $allowed = array_keys($api->getIncludes());

        if ($includes === null || array_diff($includes, $allowed) !== []) {
            throw ListQuery::invalid('include', (string) __('alfakit-api::api.problems.invalid_include'), ['allowed' => $allowed]);
        }

        return $includes;
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function recordUrl(Request $request, ResourceApi $api, string $resource, Model $record): string
    {
        $key = $record->getAttribute($resource::getRecordRouteKeyName() ?? $record->getRouteKeyName());

        return Str::finish($request->url(), '/').rawurlencode((string) $key);
    }
}
