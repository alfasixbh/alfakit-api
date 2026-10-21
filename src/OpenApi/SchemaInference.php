<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\OpenApi;

use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\KeyValue;
use AlfaSix\Alfakit\Forms\Components\Radio;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TagsInput;
use AlfaSix\Alfakit\Forms\Components\TimePicker;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Forms\Components\ToggleButtons;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitApi\Support\RecordSerializer;
use Closure;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use Stringable;
use Throwable;

/**
 * JSON Schemas (OpenAPI 3.1 dialect) of a resource's output fields and form input,
 * inferred from the model's casts and columns and from the form fields and their
 * validation rules.
 *
 * @internal
 */
final class SchemaInference
{
    public const MAX_ENUM_OPTIONS = 200;

    /**
     * The request body schema of `store` (`$forCreate`, with required fields) or `update`.
     *
     * @param  list<string>|null  $writable
     * @return array<string, mixed>
     */
    public static function input(Schema $form, ?array $writable, bool $forCreate): array
    {
        $properties = [];
        $required = [];

        foreach ($form->getFlatFields() as $path => $field) {
            $path = (string) $path;

            // Nested state (`address.street`) and repeater items are described by their root.
            if (str_contains($path, '.') || $field instanceof FileUpload || $field->isNestedInRepeater($form) || $field->isDisabled() || $field->isReadOnly()) {
                continue;
            }

            if ($writable !== null && ! in_array($path, $writable, true)) {
                continue;
            }

            $properties[$path] = self::field($field);

            if ($forCreate && $field->isRequired()) {
                $required[] = $path;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties === [] ? new stdClass : $properties,
            'required' => $required === [] ? null : $required,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function field(Field $field): array
    {
        $rules = self::rules($field);
        $schema = match (true) {
            $field instanceof Toggle, $field instanceof Checkbox => ['type' => 'boolean'],
            $field instanceof CheckboxList, $field instanceof TagsInput => ['type' => 'array', 'items' => self::optionsSchema($field) ?? ['type' => 'string']],
            $field instanceof Select && $field->isMultiple() => ['type' => 'array', 'items' => self::optionsSchema($field) ?? ['type' => ['string', 'integer']]],
            $field instanceof Select, $field instanceof Radio, $field instanceof ToggleButtons => self::optionsSchema($field) ?? ['type' => ['string', 'integer']],
            $field instanceof DateTimePicker => ['type' => 'string', 'format' => 'date-time'],
            $field instanceof DatePicker => ['type' => 'string', 'format' => 'date'],
            $field instanceof TimePicker => ['type' => 'string', 'format' => 'time'],
            $field instanceof Repeater => ['type' => 'array', 'items' => ['type' => 'object']],
            $field instanceof KeyValue => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            default => self::fromRules($rules),
        };

        foreach ($rules as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
            $isString = ($schema['type'] ?? null) === 'string';
            $isNumber = in_array($schema['type'] ?? null, ['integer', 'number'], true);

            match (true) {
                $name === 'max' && is_numeric($argument) && $isString => $schema['maxLength'] = (int) $argument,
                $name === 'min' && is_numeric($argument) && $isString => $schema['minLength'] = (int) $argument,
                $name === 'max' && is_numeric($argument) && $isNumber => $schema['maximum'] = $argument + 0,
                $name === 'min' && is_numeric($argument) && $isNumber => $schema['minimum'] = $argument + 0,
                default => null,
            };
        }

        if (! $field->isRequired() || in_array('nullable', $rules, true)) {
            $schema = self::nullable($schema);
        }

        $label = self::safe(static fn (): ?string => $field->getLabel());
        $helper = self::safe(static fn (): ?string => $field->getHelperText());

        return array_filter([...$schema, 'title' => is_string($label) && $label !== '' ? $label : null, 'description' => is_string($helper) && $helper !== '' ? strip_tags($helper) : null], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function fromRules(array $rules): array
    {
        $names = array_map(static fn (string $rule): string => explode(':', $rule, 2)[0], $rules);

        return match (true) {
            in_array('integer', $names, true) => ['type' => 'integer'],
            in_array('numeric', $names, true), in_array('decimal', $names, true) => ['type' => 'number'],
            in_array('boolean', $names, true), in_array('accepted', $names, true) => ['type' => 'boolean'],
            in_array('array', $names, true), in_array('list', $names, true) => ['type' => 'array'],
            in_array('email', $names, true) => ['type' => 'string', 'format' => 'email'],
            in_array('url', $names, true), in_array('active_url', $names, true) => ['type' => 'string', 'format' => 'uri'],
            in_array('uuid', $names, true) => ['type' => 'string', 'format' => 'uuid'],
            in_array('date', $names, true) => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
        };
    }

    /**
     * The field's rules that can be written as strings (`max:255`).
     *
     * @return list<string>
     */
    private static function rules(Field $field): array
    {
        $rules = self::safe(static fn (): array => $field->getValidationRules()) ?? [];
        $strings = [];

        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $strings[] = $rule;
            } elseif ($rule instanceof Stringable) {
                $strings[] = (string) $rule;
            }
        }

        return $strings;
    }

    /**
     * An `enum` of the field's option keys, when it has a reasonable static list.
     *
     * @return array<string, mixed>|null
     */
    private static function optionsSchema(Field $field): ?array
    {
        if ($field instanceof Select && $field->hasRelationship()) {
            return ['type' => ['string', 'integer']];
        }

        if (! method_exists($field, 'getOptions')) {
            return null;
        }

        $options = self::safe(static fn (): mixed => $field->getOptions());

        if (! is_array($options) || $options === [] || count($options) > self::MAX_ENUM_OPTIONS) {
            return null;
        }

        $keys = [];

        // Grouped options (`['Group' => ['a' => 'A']]`) list their keys too.
        foreach ($options as $key => $label) {
            if (is_array($label)) {
                array_push($keys, ...array_keys($label));
            } else {
                $keys[] = $key;
            }
        }

        $allIntegers = array_filter($keys, is_int(...)) === $keys;

        return ['type' => $allIntegers ? 'integer' : 'string', 'enum' => array_values(array_map(static fn (int|string $key): int|string => $allIntegers ? $key : (string) $key, $keys))];
    }

    /**
     * The schema of one output field, from the model's casts or column type.
     *
     * @param  array<string, string>  $columnTypes
     * @return array<string, mixed>
     */
    public static function output(Model $model, string $attribute, array $columnTypes): array
    {
        $casts = $model->getCasts();
        $cast = isset($casts[$attribute]) ? strtolower(explode(':', (string) $casts[$attribute], 2)[0]) : null;

        $schema = match (true) {
            $attribute === $model->getKeyName() && $model->getIncrementing() => ['type' => 'integer'],
            $cast !== null && in_array($cast, ['int', 'integer', 'timestamp'], true) => ['type' => 'integer'],
            $cast !== null && in_array($cast, ['real', 'float', 'double', 'decimal'], true) => ['type' => 'number'],
            $cast !== null && in_array($cast, ['bool', 'boolean'], true) => ['type' => 'boolean'],
            $cast !== null && in_array($cast, ['date', 'immutable_date'], true) => ['type' => 'string', 'format' => 'date-time'],
            $cast !== null && in_array($cast, ['datetime', 'immutable_datetime', 'custom_datetime'], true) => ['type' => 'string', 'format' => 'date-time'],
            $cast !== null && in_array($cast, ['array', 'json', 'collection', 'object', 'encrypted:array', 'encrypted:collection', 'encrypted:json', 'encrypted:object'], true) => ['type' => ['object', 'array']],
            in_array($attribute, [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()], true) => ['type' => 'string', 'format' => 'date-time'],
            default => self::fromColumnType($columnTypes[$attribute] ?? null),
        };

        return self::nullable($schema);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fromColumnType(?string $type): array
    {
        $type = strtolower((string) $type);

        return match (true) {
            $type === '' => [],
            str_contains($type, 'int') => ['type' => 'integer'],
            str_contains($type, 'bool') => ['type' => 'boolean'],
            str_contains($type, 'decimal'), str_contains($type, 'numeric'), str_contains($type, 'float'), str_contains($type, 'double'), str_contains($type, 'real') => ['type' => 'number'],
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => ['type' => 'string', 'format' => 'date-time'],
            $type === 'date' => ['type' => 'string', 'format' => 'date'],
            str_contains($type, 'json') => ['type' => ['object', 'array']],
            default => ['type' => 'string'],
        };
    }

    /**
     * Column types of a model's table, by column name.
     *
     * @return array<string, string>
     */
    public static function columnTypes(Model $model): array
    {
        try {
            $types = [];

            foreach ($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()) as $column) {
                $types[$column['name']] = $column['type_name'];
            }

            return $types;
        } catch (Throwable) {
            return array_fill_keys(RecordSerializer::columnListing($model), '');
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function nullable(array $schema): array
    {
        if ($schema === []) {
            return $schema;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            $schema['type'] = [$type, 'null'];
        } elseif (is_array($type) && ! in_array('null', $type, true)) {
            $schema['type'] = [...$type, 'null'];
        }

        return $schema;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null
     */
    private static function safe(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
