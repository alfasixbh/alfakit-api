<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http;

use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An RFC 7807 / RFC 9457 problem document (`application/problem+json`): every error the
 * API returns has this shape.
 *
 * ```json
 * {"type": "https://alfakit.dev/problems/validation", "title": "Unprocessable Content", "status": 422,
 *  "detail": "The given data was invalid.", "instance": "/api/v1/posts", "errors": {"title": ["…"]}}
 * ```
 */
final class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    public const TYPE_BASE = 'https://alfakit.dev/problems/';

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly ?string $detail = null,
        public readonly ?string $type = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, ?string $detail = null, ?string $type = null, array $extensions = [], array $headers = []): self
    {
        return new self($status, $detail, $type, $extensions, $headers);
    }

    public static function title(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Request $request = null): array
    {
        return array_filter([
            'type' => $this->type === null ? 'about:blank' : self::TYPE_BASE.$this->type,
            'title' => self::title($this->status),
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $request === null ? null : '/'.ltrim($request->path(), '/'),
            ...$this->extensions,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function toResponse(?Request $request = null): JsonResponse
    {
        return new JsonResponse($this->toArray($request), $this->status, [...$this->headers, 'Content-Type' => self::CONTENT_TYPE], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The problem for an exception thrown while serving an API request.
     */
    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof ProblemException) {
            return $exception->problem;
        }

        if ($exception instanceof ValidationException) {
            return self::make(422, (string) __('alfakit-api::api.problems.validation'), 'validation', ['errors' => $exception->errors()]);
        }

        if ($exception instanceof AuthenticationException) {
            return self::make(401, (string) __('alfakit-api::api.problems.unauthenticated'), 'unauthenticated', headers: ['WWW-Authenticate' => 'Bearer']);
        }

        if ($exception instanceof AuthorizationException) {
            return self::make(403, (string) __('alfakit-api::api.problems.forbidden'), 'forbidden');
        }

        if ($exception instanceof ModelNotFoundException) {
            return self::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
        }

        if ($exception instanceof Halt) {
            return self::make(409, (string) __('alfakit-api::api.problems.halted'), 'halted');
        }

        if ($exception instanceof HttpResponseException) {
            $status = $exception->getResponse()->getStatusCode();

            return self::make($status >= 400 ? $status : 500);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = array_map(static fn (mixed $value): string => is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value, $exception->getHeaders());

            return self::make($status, self::defaultDetail($status), self::defaultType($status), headers: $headers);
        }

        return self::make(500, (string) __('alfakit-api::api.problems.server_error'), 'server-error');
    }

    protected static function defaultDetail(int $status): ?string
    {
        return match ($status) {
            401 => (string) __('alfakit-api::api.problems.unauthenticated'),
            403 => (string) __('alfakit-api::api.problems.forbidden'),
            404 => (string) __('alfakit-api::api.problems.not_found'),
            405 => (string) __('alfakit-api::api.problems.method_not_allowed'),
            429 => (string) __('alfakit-api::api.problems.too_many_requests'),
            default => $status >= 500 ? (string) __('alfakit-api::api.problems.server_error') : null,
        };
    }

    protected static function defaultType(int $status): ?string
    {
        return match ($status) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not-found',
            429 => 'rate-limited',
            default => $status >= 500 ? 'server-error' : null,
        };
    }
}
