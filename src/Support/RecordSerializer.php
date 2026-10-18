<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitApi\ApiPlugin;
use AlfaSix\AlfakitApi\ResourceApi;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Turns records into API output: only whitelisted fields, never the model's `$hidden`
 * attributes, with the model's casts applied, plus the requested includes.
 *
 * @internal
 */
final class RecordSerializer
{
    /** @var array<string, string|Closure>|null */
    private ?array $fields = null;

    /** @var array<class-string, list<string>> */
    private static array $columnListings = [];

    public function __construct(
        private readonly Panel $panel,
        private readonly ApiPlugin $plugin,
        private readonly ResourceApi $api,
    ) {}

    public static function for(Panel $panel, ApiPlugin $plugin, ResourceApi $api): self
    {
        return new self($panel, $plugin, $api);
    }

    public static function flushColumnListings(): void
    {
        self::$columnListings = [];
    }

    /**
     * The output fields, by name: an attribute name or a closure computing the value.
     *
     * @return array<string, string|Closure>
     */
    public function getFields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }

        $model = $this->newModel();
        $configured = $this->api->getFields();
        $fields = [];

        if ($configured === null) {
            foreach ($this->getDefaultFieldNames($model) as $name) {
                $fields[$name] = $name;
            }
        } else {
            foreach ($configured as $name => $source) {
                if (is_int($name) && is_string($source)) {
                    $fields[$source] = $source;
                } elseif (is_string($name) && ($source instanceof Closure || is_string($source))) {
                    $fields[$name] = $source;
                }
            }
        }

        $hidden = [...$model->getHidden(), ...$this->api->getHiddenFields()];
        $visible = $model->getVisible();

        foreach ($fields as $name => $source) {
            // Attributes the model hides are never exposed, whatever the configuration says.
            if (is_string($source) && (in_array($source, $hidden, true) || ($visible !== [] && ! in_array($source, $visible, true)))) {
                unset($fields[$name]);

                continue;
            }

            if (in_array($name, $this->api->getHiddenFields(), true)) {
                unset($fields[$name]);
            }
        }

        return $this->fields = $fields;
    }

    /**
     * @return list<string>
     */
    public function getFieldNames(): array
    {
        return array_keys($this->getFields());
    }

    /**
     * The primary key, the form fields and table columns that are real attributes
     * (columns, appended or accessor attributes), and the timestamps.
     *
     * @return list<string>
     */
    private function getDefaultFieldNames(Model $model): array
    {
        $resource = $this->api->getResource();
        $names = [$model->getKeyName()];

        try {
            $form = $resource::form(Schema::make()->model($model::class)->operation('edit'));

            foreach (array_keys($form->getFlatFields(withHidden: true)) as $path) {
                $names[] = explode('.', (string) $path)[0];
            }
        } catch (Throwable) {
            // A form that can't be built outside its page contributes nothing.
        }

        try {
            $table = $resource::table(Table::make()->model($model::class));

            foreach ($table->getColumns() as $column) {
                $names[] = $column->getName();
            }
        } catch (Throwable) {
            // Same for tables.
        }

        if ($model->usesTimestamps()) {
            $names[] = (string) $model->getCreatedAtColumn();
            $names[] = (string) $model->getUpdatedAtColumn();
        }

        $columns = self::columnListing($model);
        $appends = $model->getAppends();

        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => ! str_contains($name, '.') && (in_array($name, $columns, true) || in_array($name, $appends, true) || $model->hasGetMutator($name) || $model->hasAttributeMutator($name)),
        )));
    }

    /**
     * The table columns of a model (cached per request).
     *
     * @return list<string>
     */
    public static function columnListing(Model $model): array
    {
        return self::$columnListings[$model::class] ??= (static function () use ($model): array {
            try {
                return array_values(array_map(strval(...), $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable())));
            } catch (Throwable) {
                return [];
            }
        })();
    }

    /**
     * @param  list<string>|null  $only  Sparse fieldset (`?fields=`); null for every field
     * @param  list<string>  $includes  Relations to embed (already validated)
     * @return array<string, mixed>
     */
    public function serialize(Model $record, ?array $only = null, array $includes = []): array
    {
        $fields = $this->getFields();

        if ($only !== null) {
            $fields = array_intersect_key($fields, array_flip($only));
        }

        $output = $this->serializeFields($record, $fields, isExplicit: $this->api->getFields() !== null);

        foreach ($includes as $relation) {
            $output[$relation] = $this->serializeInclude($record, $relation);
        }

        return $output;
    }

    /**
     * @param  array<string, string|Closure>  $fields
     * @return array<string, mixed>
     */
    private function serializeFields(Model $record, array $fields, bool $isExplicit = true): array
    {
        // Casts, date serialisation, `$appends` and `$hidden` as the model defines them.
        $attributes = $record->attributesToArray();
        $hidden = $record->getHidden();
        $output = [];

        foreach ($fields as $name => $source) {
            if ($source instanceof Closure) {
                $output[$name] = self::normalize($this->api->evaluate($source, ['record' => $record, 'model' => $record], [Model::class => $record, $record::class => $record]));

                continue;
            }

            if (in_array($source, $hidden, true)) {
                continue;
            }

            if (array_key_exists($source, $attributes)) {
                $output[$name] = $attributes[$source];
            } elseif ($record->hasGetMutator($source) || $record->hasAttributeMutator($source)) {
                $output[$name] = self::normalize($record->getAttribute($source));
            } elseif ($isExplicit) {
                $output[$name] = null;
            }
        }

        return $output;
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function serializeInclude(Model $record, string $relation): ?array
    {
        $related = $record->relationLoaded($relation) ? $record->getRelation($relation) : $record->{$relation};

        if ($related instanceof Model) {
            return $this->canView($related) ? $this->serializeRelated($related, $relation) : null;
        }

        if ($related instanceof EloquentCollection) {
            $items = [];

            foreach ($related as $item) {
                if ($this->canView($item)) {
                    $items[] = $this->serializeRelated($item, $relation);
                }
            }

            return $items;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRelated(Model $related, string $relation): array
    {
        $configured = $this->api->getIncludes()[$relation] ?? null;

        if ($configured !== null) {
            $fields = array_combine($configured, $configured);
        } else {
            $resource = $this->panel->getModelResource($related);
            $relatedApi = $resource === null ? null : $this->plugin->getResourceApiFor($this->panel, $resource);
            $fields = $relatedApi === null
                ? [$related->getKeyName() => $related->getKeyName()]
                : self::for($this->panel, $this->plugin, $relatedApi)->getFields();
        }

        return $this->serializeFields($related, $fields);
    }

    /**
     * Included records the user may not view (per the related resource's policy) are left out.
     */
    private function canView(Model $related): bool
    {
        $resource = $this->panel->getModelResource($related);

        return $resource === null || $resource::canView($related);
    }

    private function newModel(): Model
    {
        $model = $this->api->getResource()::getModel();

        return new $model;
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => Carbon::instance($value)->toJSON(),
            $value instanceof Model => $value->getKey(),
            $value instanceof Arrayable => array_map(self::normalize(...), $value->toArray()),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            is_array($value) => array_map(self::normalize(...), $value),
            $value instanceof Stringable => (string) $value,
            default => null,
        };
    }
}
