# Changelog

All notable changes to `alfasix/alfakit-api` are documented in this file.

## Unreleased

- Initial release: opt-in JSON REST APIs for resources (`ApiPlugin::make()->resources([...])`, `ResourceApi` and the `ExposesApi` contract) with `index` (pagination, sorting, search and filters from the resource table, sparse fieldsets, whitelisted includes), `show`, `store`, `update`, `destroy` and opted-in bulk actions; writes through the resource's own Create / Edit pages (form validation, defaults, `mutateFormDataBefore…()`, `handleRecord…()` and lifecycle hooks); field whitelists that never expose `$hidden` attributes; policies and token abilities on every call; tenant scoping with tenant-bound tokens (`/{tenant}/…` or `tenantFromToken()`); hashed personal access tokens behind a `TokenRepository` contract with an "API tokens" page (abilities per resource and operation, expiry, last use, revoke); per-token rate limiting; RFC 9457 problem responses; `ETag` / `If-None-Match` / `If-Match`; `Idempotency-Key` for `store`; an OpenAPI 3.1 document (`/api/v1/openapi.json`) and an "API reference" page.
