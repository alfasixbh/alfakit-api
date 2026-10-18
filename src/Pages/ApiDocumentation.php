<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\CodeEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\Http\Controllers\DocumentationController;
use AlfaSix\AlfakitApi\OpenApi\Example;
use AlfaSix\AlfakitApi\OpenApi\OpenApiGenerator;
use Illuminate\Support\Facades\Route;

/**
 * The API reference of the panel, rendered from the generated OpenAPI document with
 * AlfaKit components: base URL and authentication, then every resource's endpoints,
 * parameters, abilities and example payloads, and the error format.
 *
 * `{page}/openapi.json` serves the same document to signed-in users (for download or
 * an external viewer).
 */
class ApiDocumentation extends Page
{
    protected static ?string $slug = 'api-docs';

    protected static bool $isDiscovered = false;

    protected static ?string $navigationIcon = 'book-open';

    /** @var array<string, mixed>|null */
    protected ?array $document = null;

    public static function getPlugin(): ApiPlugin
    {
        return ApiPlugin::get() ?? ApiPlugin::make();
    }

    public static function canAccess(): bool
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user() !== null && static::getPlugin()->userCanViewDocumentation();
    }

    public function getTitle(): string
    {
        return (string) __('alfakit-api::api.docs.title');
    }

    public function getSubheading(): ?string
    {
        return (string) __('alfakit-api::api.docs.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('alfakit-api::api.docs.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return static::getPlugin()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $sort = static::getPlugin()->getNavigationSort();

        return $sort === null ? null : $sort + 1;
    }

    /**
     * The page, plus `{page}/openapi.json` for signed-in users.
     */
    public static function registerRoutes(Panel $panel): void
    {
        parent::registerRoutes($panel);

        Route::get(static::getClusterRoutePrefix().ltrim(static::getRoutePath(), '/').'/openapi.json', DocumentationController::class)
            ->name(static::getRelativeRouteName().'.openapi');
    }

    public static function getDocumentUrl(): string
    {
        return alfakit()->getCurrentOrDefaultPanel()->route(static::getRelativeRouteName().'.openapi');
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->document = null;
    }

    protected function getHeaderActions(): array
    {
        $tokens = static::getPlugin()->getTokensPage();

        return array_values(array_filter([
            Action::make('openapi')
                ->label(__('alfakit-api::api.docs.download'))
                ->icon('file-json')
                ->color('gray')
                ->button()
                ->url(static fn (): string => static::getDocumentUrl(), shouldOpenInNewTab: true),
            $tokens !== null && $tokens::canAccess()
                ? Action::make('tokens')
                    ->label(__('alfakit-api::api.docs.manage_tokens'))
                    ->icon('key-round')
                    ->button()
                    ->url(static fn (): string => $tokens::getUrl())
                : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(): array
    {
        return $this->document ??= OpenApiGenerator::make($this->getPanel(), static::getPlugin())->generate();
    }

    public function infolist(Schema $schema): Schema
    {
        $document = $this->getDocument();
        $generator = OpenApiGenerator::make($this->getPanel(), static::getPlugin());
        $components = [$this->getIntroductionSection($document, $generator)];

        foreach ($generator->getDocumentedResources() as $slug => $api) {
            $components[] = $this->getResourceSection($document, $generator, $slug, $api->getLabel(), $api->getDescription(), $generator->schemaName($api));
        }

        $components[] = $this->getErrorsSection();

        return $schema->components($components);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function getIntroductionSection(array $document, OpenApiGenerator $generator): Section
    {
        $plugin = static::getPlugin();
        $baseUrl = $generator->serverUrl();
        $limit = $plugin->hasPerTokenRateLimit() ? null : $plugin->getRateLimit(null, null);
        $example = 'curl '.$baseUrl.'/token \\'."\n".'  -H "Authorization: Bearer $TOKEN" \\'."\n".'  -H "Accept: application/json"';

        return Section::make(__('alfakit-api::api.docs.introduction.heading'))
            ->description(__('alfakit-api::api.docs.introduction.description'))
            ->icon('plug')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema(array_values(array_filter([
                    TextEntry::make('base_url')
                        ->label(__('alfakit-api::api.docs.introduction.base_url'))
                        ->state($baseUrl)
                        ->fontFamily('mono')
                        ->copyable(),
                    TextEntry::make('authentication')
                        ->label(__('alfakit-api::api.docs.introduction.authentication'))
                        ->state('Authorization: Bearer {token}')
                        ->fontFamily('mono')
                        ->helperText(__('alfakit-api::api.docs.introduction.authentication_help')),
                    TextEntry::make('rate_limit')
                        ->label(__('alfakit-api::api.docs.introduction.rate_limit'))
                        ->state($plugin->hasPerTokenRateLimit() ? __('alfakit-api::api.docs.introduction.per_token_rate_limit') : ($limit === null ? __('alfakit-api::api.docs.introduction.no_rate_limit') : trans_choice('alfakit-api::api.docs.introduction.rate_limit_value', $limit, ['count' => $limit]))),
                    $generator->hasTenantSegment()
                        ? TextEntry::make('tenant')
                            ->label(__('alfakit-api::api.docs.introduction.tenant'))
                            ->state(__('alfakit-api::api.docs.introduction.tenant_value'))
                        : null,
                ]))),
                CodeEntry::make('example')
                    ->label(__('alfakit-api::api.docs.introduction.example'))
                    ->state($example)
                    ->copyable(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function getResourceSection(array $document, OpenApiGenerator $generator, string $slug, string $label, ?string $description, string $schemaName): Section
    {
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];
        $schemas = is_array($document['components']['schemas'] ?? null) ? $document['components']['schemas'] : [];
        $collection = $generator->collectionPath($slug);
        $endpoints = [];
        $parameters = [];

        foreach ($paths as $path => $operations) {
            if (! is_string($path) || ($path !== $collection && ! str_starts_with($path, $collection.'/')) || ! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                $endpoints[] = $this->getEndpointRow((string) $method, $path, $operation);

                if ($method === 'get' && $path === $collection) {
                    foreach ($operation['parameters'] ?? [] as $parameter) {
                        if (is_array($parameter) && isset($parameter['name']) && is_string($parameter['name'])) {
                            $parameters[] = $parameter['name'];
                        }
                    }
                }
            }
        }

        $fields = array_keys(is_array($schemas[$schemaName]['properties'] ?? null) ? $schemas[$schemaName]['properties'] : []);
        $input = is_array($schemas[$schemaName.'CreateInput'] ?? null) ? $schemas[$schemaName.'CreateInput'] : null;

        $examples = array_values(array_filter([
            CodeEntry::make($slug.'_response')
                ->label(__('alfakit-api::api.docs.resource.response_example'))
                ->state(['data' => Example::from(['$ref' => '#/components/schemas/'.$schemaName], $schemas)])
                ->grammar('json')
                ->copyable(),
            $input === null ? null : CodeEntry::make($slug.'_request')
                ->label(__('alfakit-api::api.docs.resource.request_example'))
                ->state(Example::from($input, $schemas))
                ->grammar('json')
                ->copyable(),
        ]));

        return Section::make($label)
            ->description($description)
            ->collapsible()
            ->schema([
                ...$endpoints,
                Grid::make(['default' => 1, 'md' => 2])->schema(array_values(array_filter([
                    TextEntry::make($slug.'_fields')
                        ->label(__('alfakit-api::api.docs.resource.fields'))
                        ->state($fields)
                        ->badge()
                        ->color('gray')
                        ->placeholder(__('alfakit-api::api.docs.resource.none')),
                    $parameters === [] ? null : TextEntry::make($slug.'_parameters')
                        ->label(__('alfakit-api::api.docs.resource.parameters'))
                        ->state(array_values(array_unique($parameters)))
                        ->badge()
                        ->color('gray'),
                ]))),
                Grid::make(['default' => 1, 'lg' => count($examples)])->schema($examples),
            ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    protected function getEndpointRow(string $method, string $path, array $operation): Component
    {
        $color = match ($method) {
            'get' => 'info',
            'post' => 'success',
            'put', 'patch' => 'warning',
            'delete' => 'danger',
            default => 'gray',
        };

        return Flex::make(array_values(array_filter([
            Text::make(strtoupper($method))->badge()->color($color)->fontFamily('mono')->grow(false),
            Text::make($path)->fontFamily('mono')->copyable()->grow(false),
            Text::make((string) ($operation['summary'] ?? ''))->color('gray'),
            isset($operation['x-ability']) && is_string($operation['x-ability'])
                ? Text::make($operation['x-ability'])->badge()->color('gray')->tooltip(__('alfakit-api::api.docs.resource.ability'))->grow(false)
                : null,
        ])))->from('md')->verticallyAlignCenter();
    }

    protected function getErrorsSection(): Section
    {
        $example = [
            'type' => 'https://alfakit.dev/problems/validation',
            'title' => 'Unprocessable Content',
            'status' => 422,
            'detail' => (string) __('alfakit-api::api.problems.validation'),
            'instance' => '/api/v1/posts',
            'errors' => ['title' => [(string) __('validation.required', ['attribute' => 'title'])]],
        ];

        return Section::make(__('alfakit-api::api.docs.errors.heading'))
            ->description(__('alfakit-api::api.docs.errors.description'))
            ->icon('triangle-alert')
            ->collapsible()
            ->collapsed()
            ->schema([
                TextEntry::make('statuses')
                    ->label(__('alfakit-api::api.docs.errors.statuses'))
                    ->state([
                        '400 '.__('alfakit-api::api.docs.errors.400'),
                        '401 '.__('alfakit-api::api.docs.errors.401'),
                        '403 '.__('alfakit-api::api.docs.errors.403'),
                        '404 '.__('alfakit-api::api.docs.errors.404'),
                        '409 '.__('alfakit-api::api.docs.errors.409'),
                        '412 '.__('alfakit-api::api.docs.errors.412'),
                        '422 '.__('alfakit-api::api.docs.errors.422'),
                        '429 '.__('alfakit-api::api.docs.errors.429'),
                    ])
                    ->listWithLineBreaks(),
                CodeEntry::make('problem_example')
                    ->label(__('alfakit-api::api.docs.errors.example'))
                    ->state($example)
                    ->grammar('json'),
            ]);
    }
}
