<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Throw to answer an API request with a problem document.
 */
final class ProblemException extends RuntimeException
{
    public function __construct(public readonly Problem $problem)
    {
        parent::__construct($problem->detail ?? Problem::title($problem->status), $problem->status);
    }

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function make(int $status, ?string $detail = null, ?string $type = null, array $extensions = [], array $headers = []): self
    {
        return new self(Problem::make($status, $detail, $type, $extensions, $headers));
    }

    /**
     * Rendered by Laravel's exception handler as the problem document.
     */
    public function render(Request $request): JsonResponse
    {
        return $this->problem->toResponse($request);
    }

    /**
     * An expected answer, not an error: never logged.
     */
    public function report(): void {}
}
