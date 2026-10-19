# AlfaKit API

<p><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-white.svg"><img src="https://raw.githubusercontent.com/alfasixbh/alfakit/main/art/alfasix/alfa-six-micro-navy.svg" alt="" width="24" height="18" align="top"></picture> <sub>by <a href="https://alfasixbh.com">Alfa Six</a></sub></p>

> This is a read-only split of the [AlfaKit monorepo](https://github.com/alfasixbh/alfakit/tree/main/packages/plugins/api). Please open issues and pull requests there.

Automatic, secure JSON REST APIs for the resources of an [AlfaKit](https://alfakit.dev) panel. The API runs each resource's own table (sorting, search, filters), form (validation, defaults, page hooks) and policies, so it can't drift from the panel. It ships hashed personal access tokens with per-resource abilities, tenant isolation, rate limiting, RFC 9457 errors, ETags, idempotency keys and a generated OpenAPI 3.1 document with an in-panel API reference.

## Installation

```bash
composer require alfasix/alfakit-api
php artisan vendor:publish --tag=alfakit-api-migrations
php artisan migrate
```

Laravel Sanctum is not required.

## Register

```php
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\ResourceApi;

$panel->plugin(
    ApiPlugin::make()
        ->resources([
            CategoryResource::class,
            ResourceApi::make(PostResource::class)
                ->fields(['id', 'title', 'status', 'published_at'])
                ->includes(['author' => ['id', 'name']])
                ->bulkActions(['publish']),
        ])
        ->rateLimit(120),
);
```

Users create tokens on the **API tokens** page and read the **API reference** page; clients call `/api/v1/posts` with `Authorization: Bearer {token}`.

See the [documentation](https://alfakit.dev/docs/plugins/api) for every option.

## Testing

```bash
vendor/bin/pest --test-directory=packages/plugins packages/plugins/api
```

## License

MIT. See [LICENSE.md](LICENSE.md).
