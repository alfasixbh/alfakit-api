<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\OpenApi;

/**
 * Example values for JSON Schemas of the generated document (for the API reference page).
 *
 * @internal
 */
final class Example
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components  `components.schemas`, to follow `$ref`s
     */
    public static function from(array $schema, array $components, int $depth = 0): mixed
    {
        if ($depth > 5) {
            return null;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));
            $target = $components[$name] ?? null;

            return is_array($target) ? self::from($target, $components, $depth + 1) : null;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? null;

        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $candidate): bool => $candidate !== 'null'))[0] ?? null;
        }

        $format = $schema['format'] ?? null;

        return match ($type) {
            'integer' => 1,
            'number' => 1.5,
            'boolean' => true,
            'array' => [self::from(is_array($schema['items'] ?? null) ? $schema['items'] : ['type' => 'string'], $components, $depth + 1)],
            'object' => self::object($schema, $components, $depth),
            'string' => match ($format) {
                'date-time' => '2026-01-01T09:30:00.000000Z',
                'date' => '2026-01-01',
                'time' => '09:30:00',
                'email' => 'ada@example.com',
                'uri' => 'https://example.com',
                'uuid' => '9b2f8c4e-3d5a-4b1e-8f7a-2c6d9e0b1a3f',
                default => 'string',
            },
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private static function object(array $schema, array $components, int $depth): array
    {
        $example = [];
        $properties = $schema['properties'] ?? [];

        foreach (is_array($properties) ? $properties : [] as $name => $property) {
            $example[(string) $name] = is_array($property) ? self::from($property, $components, $depth + 1) : null;
        }

        return $example;
    }
}
