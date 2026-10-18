<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi;

use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitApi\Contracts\AccessToken;
use AlfaSix\AlfakitApi\Contracts\ExposesApi;
use AlfaSix\AlfakitApi\Contracts\TokenRepository;
use AlfaSix\AlfakitApi\Pages\ApiDocumentation;
use AlfaSix\AlfakitApi\Pages\ApiTokens;
use AlfaSix\AlfakitApi\Tokens\DatabaseTokenRepository;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * JSON REST APIs for a panel's resources.
 *
 * ```php
 * $panel->plugin(
 *     ApiPlugin::make()
 *         ->resources([
 *             PostResource::class,
 *             ResourceApi::make(CategoryResource::class)->readOnly(),
 *         ])
 *         ->rateLimit(120),
 * );
 * ```
 *
 * Every request needs a personal access token (`Authorization: Bearer …`) created on the
 * plugin's "API tokens" page; the resource policies, the token's abilities and (on tenancy
 * panels) the tenant are checked on every call.
 */
class ApiPlugin implements Plugin
{
    use EvaluatesClosures;

    public const ID = 'api';

    /** Requests per minute and IP address without a token, when the limit is per token. */
    public const ANONYMOUS_RATE_LIMIT = 60;

    /** @var array<int, class-string<resource>|ResourceApi> */
    protected array $resources = [];

    protected ?string $prefix = null;

    protected string $version = 'v1';

    protected ?string $domain = null;

    /** @var array<int, string> */
    protected array $middleware = [];

    protected int|Closure|null $rateLimit = 60;

    protected int $defaultPerPage = 15;

    protected int $maxPerPage = 100;

    protected bool $isTenantFromToken = false;

    protected ?TokenRepository $tokenRepository = null;

    /** @var array<int, int|null> */
    protected array $tokenExpirationOptions = [30, 90, 365, null];

    protected ?int $defaultTokenExpiration = 90;

    /** @var class-string<ApiTokens>|null */
    protected ?string $tokensPage = ApiTokens::class;

    /** @var class-string<ApiDocumentation>|null */
    protected ?string $documentationPage = ApiDocumentation::class;

    protected bool $isDocumentationPublic = false;

    protected int $idempotencyTtl = 1440;

    protected ?string $idempotencyStore = null;

    protected bool|Closure $canManageTokens = true;

    protected bool|Closure $canViewDocumentation = true;

    protected string|Closure|null $title = null;

    protected string|Closure|null $description = null;

    protected string|Closure|null $navigationGroup = null;

    protected ?int $navigationSort = null;

    /** @var array<string, ResourceApi>|null */
    protected ?array $resolvedResources = null;

    public static function make(): static
    {
        return new static;
    }

    /**
     * The plugin of `$panel` (the current panel by default), or null when it has none.
     */
    public static function get(?Panel $panel = null): ?static
    {
        $panel ??= alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        if ($panel === null || ! $panel->hasPlugin(self::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof static ? $plugin : null;
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $pages = array_values(array_filter([$this->tokensPage, $this->documentationPage]));

        if ($pages !== []) {
            $panel->pages($pages);
        }
    }

    public function boot(Panel $panel): void {}

    protected function getEvaluationIdentifier(): string
    {
        return 'plugin';
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return match ($parameterName) {
            'panel' => [$panel],
            'user' => [$panel?->auth()->user()],
            'tenant' => [alfakit()->getTenant()],
            default => [],
        };
    }

    // ---------------------------------------------------------------- Resources

    /**
     * The resources to expose: resource classes (exposed with their defaults, or their
     * own `api()` configuration) or `ResourceApi::make(...)` configurations. Resources
     * implementing `ExposesApi` are exposed without being listed.
     *
     * @param  array<int, class-string<resource>|ResourceApi>  $resources
     */
    public function resources(array $resources): static
    {
        foreach ($resources as $resource) {
            if (! $resource instanceof ResourceApi && ! (is_string($resource) && is_a($resource, Resource::class, true))) {
                throw new InvalidArgumentException('API resources must be resource classes or ResourceApi configurations.');
            }
        }

        $this->resources = [...$this->resources, ...array_values($resources)];
        $this->resolvedResources = null;

        return $this;
    }

    /**
     * The exposed resources of `$panel`, keyed by their API slug.
     *
     * @return array<string, ResourceApi>
     */
    public function getResourceApis(Panel $panel): array
    {
        if ($this->resolvedResources !== null) {
            return $this->resolvedResources;
        }

        $configurations = [];

        foreach ($this->resources as $resource) {
            $configurations[] = $resource instanceof ResourceApi ? $resource : $this->configureResource(ResourceApi::make($resource));
        }

        $listed = array_map(static fn (ResourceApi $api): string => $api->getResource(), $configurations);

        foreach ($panel->getResources() as $resource) {
            if (is_a($resource, ExposesApi::class, true) && ! in_array($resource, $listed, true)) {
                $configurations[] = $this->configureResource(ResourceApi::make($resource));
            }
        }

        $resolved = [];

        foreach ($configurations as $configuration) {
            $slug = $configuration->getSlug();

            // Nested resources need their parent record, which API URLs don't carry.
            if ($configuration->getResource()::isNested()) {
                throw new InvalidArgumentException('['.$configuration->getResource().'] is a nested resource; nested resources cannot be exposed by the API.');
            }

            if (isset($resolved[$slug])) {
                throw new InvalidArgumentException("Two API resources use the slug [{$slug}]; give one another ->slug().");
            }

            $resolved[$slug] = $configuration;
        }

        return $this->resolvedResources = $resolved;
    }

    public function getResourceApi(Panel $panel, string $slug): ?ResourceApi
    {
        return $this->getResourceApis($panel)[$slug] ?? null;
    }

    /**
     * The API configuration of a resource class, if it is exposed.
     *
     * @param  class-string  $resource
     */
    public function getResourceApiFor(Panel $panel, string $resource): ?ResourceApi
    {
        foreach ($this->getResourceApis($panel) as $api) {
            if ($api->getResource() === $resource) {
                return $api;
            }
        }

        return null;
    }

    protected function configureResource(ResourceApi $api): ResourceApi
    {
        $resource = $api->getResource();

        if (is_a($resource, ExposesApi::class, true)) {
            return $resource::api($api);
        }

        $callable = [$resource, 'api'];

        if (is_callable($callable)) {
            $configured = $callable($api);

            return $configured instanceof ResourceApi ? $configured : $api;
        }

        return $api;
    }

    // ---------------------------------------------------------------- Routing

    /**
     * The URL prefix before the version: `api` on the default panel, `api/{panel id}` on
     * the others.
     */
    public function prefix(?string $prefix): static
    {
        $this->prefix = $prefix === null ? null : trim($prefix, '/');

        return $this;
    }

    public function getPrefix(Panel $panel): string
    {
        return $this->prefix ?? ($panel->isDefault() ? 'api' : 'api/'.$panel->getId());
    }

    /**
     * The version segment of every URL (`v1`).
     */
    public function version(string $version): static
    {
        $this->version = trim($version, '/');

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * The full path prefix: `api/v1`.
     */
    public function getBasePath(Panel $panel): string
    {
        return trim($this->getPrefix($panel).'/'.$this->getVersion(), '/');
    }

    /**
     * Serve the API on another domain (`api.example.com`).
     */
    public function domain(?string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Extra middleware for the API routes (after token authentication).
     *
     * @param  array<int, string>  $middleware
     */
    public function middleware(array $middleware): static
    {
        $this->middleware = [...$this->middleware, ...$middleware];

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * On tenancy panels the tenant comes from the URL (`/api/v1/{tenant}/posts`) by
     * default. With this, URLs have no tenant segment and every request uses the tenant
     * the token was created for.
     */
    public function tenantFromToken(bool $condition = true): static
    {
        $this->isTenantFromToken = $condition;

        return $this;
    }

    public function isTenantFromToken(): bool
    {
        return $this->isTenantFromToken;
    }

    // ---------------------------------------------------------------- Limits

    /**
     * Requests per minute and token (60 by default); null turns rate limiting off.
     * `fn (AccessToken $token, Authenticatable $user): ?int` sets it per token.
     */
    public function rateLimit(int|Closure|null $perMinute): static
    {
        $this->rateLimit = $perMinute;

        return $this;
    }

    public function getRateLimit(?AccessToken $token, ?Authenticatable $user): ?int
    {
        // A per-token closure can't be asked about anonymous requests (public documentation).
        if ($this->rateLimit instanceof Closure && $token === null) {
            return self::ANONYMOUS_RATE_LIMIT;
        }

        $limit = $this->evaluate($this->rateLimit, ['token' => $token, 'user' => $user], array_filter([AccessToken::class => $token, Authenticatable::class => $user]));

        return is_int($limit) && $limit > 0 ? $limit : null;
    }

    /**
     * Whether the limit depends on the token (a closure).
     */
    public function hasPerTokenRateLimit(): bool
    {
        return $this->rateLimit instanceof Closure;
    }

    public function defaultPerPage(int $perPage): static
    {
        $this->defaultPerPage = max(1, $perPage);

        return $this;
    }

    public function getDefaultPerPage(): int
    {
        return $this->defaultPerPage;
    }

    /**
     * The largest `per_page` a client may ask for (100 by default).
     */
    public function maxPerPage(int $perPage): static
    {
        $this->maxPerPage = max(1, $perPage);

        return $this;
    }

    public function getMaxPerPage(): int
    {
        return $this->maxPerPage;
    }

    /**
     * How long (in minutes) a `store` response is replayed for a repeated
     * `Idempotency-Key` (a day by default), and the cache store that keeps it.
     */
    public function idempotency(int $ttlMinutes = 1440, ?string $store = null): static
    {
        $this->idempotencyTtl = max(1, $ttlMinutes);
        $this->idempotencyStore = $store;

        return $this;
    }

    public function getIdempotencyTtl(): int
    {
        return $this->idempotencyTtl;
    }

    public function getIdempotencyStore(): ?string
    {
        return $this->idempotencyStore;
    }

    // ---------------------------------------------------------------- Tokens

    /**
     * Where tokens are issued and looked up (`DatabaseTokenRepository` by default).
     */
    public function tokenRepository(?TokenRepository $repository): static
    {
        $this->tokenRepository = $repository;

        return $this;
    }

    public function getTokenRepository(): TokenRepository
    {
        return $this->tokenRepository ??= app(DatabaseTokenRepository::class);
    }

    /**
     * The expiry choices (in days; null is "never") of the "Create token" form, and the
     * preselected one.
     *
     * @param  array<int, int|null>  $days
     */
    public function tokenExpirationOptions(array $days, ?int $default = null): static
    {
        $this->tokenExpirationOptions = array_values(array_unique(array_map(static fn (mixed $value): ?int => $value === null ? null : max(1, (int) $value), $days), SORT_REGULAR));
        $this->defaultTokenExpiration = $default ?? ($this->tokenExpirationOptions[0] ?? null);

        return $this;
    }

    /**
     * @return array<int, int|null>
     */
    public function getTokenExpirationOptions(): array
    {
        return $this->tokenExpirationOptions === [] ? [null] : $this->tokenExpirationOptions;
    }

    public function getDefaultTokenExpiration(): ?int
    {
        return in_array($this->defaultTokenExpiration, $this->getTokenExpirationOptions(), true) ? $this->defaultTokenExpiration : ($this->getTokenExpirationOptions()[0] ?? null);
    }

    /**
     * Who may open the "API tokens" page and create tokens: `fn (Authenticatable $user): bool`.
     */
    public function canManageTokens(bool|Closure $condition): static
    {
        $this->canManageTokens = $condition;

        return $this;
    }

    public function userCanManageTokens(): bool
    {
        return (bool) $this->evaluate($this->canManageTokens);
    }

    // ---------------------------------------------------------------- Pages

    /**
     * The token management page (`false` removes it; a subclass replaces it).
     *
     * @param  class-string<ApiTokens>|bool  $page
     */
    public function tokensPage(string|bool $page = true): static
    {
        $this->tokensPage = $this->resolvePage($page, ApiTokens::class);

        return $this;
    }

    /**
     * @return class-string<ApiTokens>|null
     */
    public function getTokensPage(): ?string
    {
        return $this->tokensPage;
    }

    /**
     * The API reference page (`false` removes it; a subclass replaces it).
     *
     * @param  class-string<ApiDocumentation>|bool  $page
     */
    public function documentationPage(string|bool $page = true): static
    {
        $this->documentationPage = $this->resolvePage($page, ApiDocumentation::class);

        return $this;
    }

    /**
     * @return class-string<ApiDocumentation>|null
     */
    public function getDocumentationPage(): ?string
    {
        return $this->documentationPage;
    }

    /**
     * Who may open the API reference page: `fn (Authenticatable $user): bool`.
     */
    public function canViewDocumentation(bool|Closure $condition): static
    {
        $this->canViewDocumentation = $condition;

        return $this;
    }

    public function userCanViewDocumentation(): bool
    {
        return (bool) $this->evaluate($this->canViewDocumentation);
    }

    /**
     * Serve `/api/v1/openapi.json` without a token (it needs any valid token by default).
     */
    public function publicDocumentation(bool $condition = true): static
    {
        $this->isDocumentationPublic = $condition;

        return $this;
    }

    public function isDocumentationPublic(): bool
    {
        return $this->isDocumentationPublic;
    }

    /**
     * The OpenAPI `info.title` (the panel's brand name followed by "API" by default).
     */
    public function title(string|Closure|null $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(Panel $panel): string
    {
        $title = $this->evaluate($this->title);

        return is_string($title) && $title !== '' ? $title : (string) __('alfakit-api::api.openapi.title', ['name' => $panel->getBrandName()]);
    }

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

    /**
     * The navigation group of the plugin's pages.
     */
    public function navigationGroup(string|Closure|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): ?string
    {
        $group = $this->evaluate($this->navigationGroup);

        return is_string($group) && $group !== '' ? $group : null;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    /**
     * @template TPage of Page
     *
     * @param  class-string<TPage>|bool  $page
     * @param  class-string<TPage>  $default
     * @return class-string<TPage>|null
     */
    protected function resolvePage(string|bool $page, string $default): ?string
    {
        if ($page === false) {
            return null;
        }

        if ($page === true) {
            return $default;
        }

        if (! is_a($page, $default, true)) {
            throw new InvalidArgumentException("[{$page}] must extend [{$default}].");
        }

        return $page;
    }
}
