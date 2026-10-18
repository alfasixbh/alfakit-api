<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Support;

use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\Filters\BaseFilter;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\AlfakitApi\Http\ProblemException;
use Illuminate\Http\Request;

/**
 * Reads and validates the list parameters of an index request against the resource's
 * table: `search`, `sort`, `filter[…]`, `page` and `per_page`.
 *
 * @internal
 */
final class ListQuery
{
    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    private function __construct(
        public readonly ?string $search,
        public readonly ?string $sortColumn,
        public readonly ?string $sortDirection,
        public readonly array $filters,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public static function fromRequest(Request $request, ?Table $table, int $defaultPerPage, int $maxPerPage): self
    {
        $search = self::scalar($request->query('search'));
        $search = $search === null || trim($search) === '' ? null : trim($search);

        if ($search !== null && ($table === null || ! self::isSearchable($table))) {
            throw self::invalid('search', (string) __('alfakit-api::api.problems.not_searchable'));
        }

        [$sortColumn, $sortDirection] = self::parseSort($request, $table);

        $page = self::scalar($request->query('page')) ?? '1';
        $perPage = self::scalar($request->query('per_page')) ?? (string) $defaultPerPage;

        if (! ctype_digit($page) || (int) $page < 1) {
            throw self::invalid('page', (string) __('alfakit-api::api.problems.invalid_page'));
        }

        if (! ctype_digit($perPage) || (int) $perPage < 1 || (int) $perPage > $maxPerPage) {
            throw self::invalid('per_page', (string) __('alfakit-api::api.problems.invalid_per_page', ['max' => $maxPerPage]));
        }

        return new self($search, $sortColumn, $sortDirection, self::parseFilters($request, $table), (int) $page, (int) $perPage);
    }

    public function toTableState(): TableState
    {
        return new TableState(
            sortColumn: $this->sortColumn,
            sortDirection: $this->sortDirection,
            search: $this->search,
            filters: $this->filters,
            page: $this->page,
            perPage: $this->perPage,
            // Every key counts as given, so state persisted in a session never applies.
            present: ['search', 'sort', 'filters', 'columnSearch', 'group', 'groupDirection', 'perPage', 'columns'],
        );
    }

    /**
     * The visible, sortable columns.
     *
     * @return list<string>
     */
    public static function sortableColumns(?Table $table): array
    {
        if ($table === null) {
            return [];
        }

        return array_values(array_map(
            static fn (Column $column): string => $column->getName(),
            array_filter($table->getColumns(), static fn (Column $column): bool => ! $column->isHidden() && $column->isSortable()),
        ));
    }

    public static function isSearchable(Table $table): bool
    {
        return $table->isSearchable();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private static function parseSort(Request $request, ?Table $table): array
    {
        $sort = self::scalar($request->query('sort'));

        if ($sort === null || $sort === '') {
            return [null, null];
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-+');

        if (! in_array($column, self::sortableColumns($table), true)) {
            throw self::invalid('sort', (string) __('alfakit-api::api.problems.invalid_sort'), ['allowed' => self::sortableColumns($table)]);
        }

        return [$column, $direction];
    }

    /**
     * `filter[status]=published`, `filter[tags]=1,2`, `filter[is_featured]=true` or a
     * filter's own fields: `filter[created_at][from]=2026-01-01`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function parseFilters(Request $request, ?Table $table): array
    {
        $input = $request->query('filter');

        if ($input === null || $input === '') {
            return [];
        }

        if (! is_array($input)) {
            throw self::invalid('filter', (string) __('alfakit-api::api.problems.invalid_filter'));
        }

        $filters = $table?->getFilters() ?? [];
        $state = [];

        foreach ($input as $name => $value) {
            $filter = is_string($name) ? ($filters[$name] ?? null) : null;

            if ($filter === null) {
                throw self::invalid('filter', (string) __('alfakit-api::api.problems.unknown_filter', ['name' => (string) $name]), ['allowed' => array_keys($filters)]);
            }

            $state[$name] = self::filterState($filter, $value);
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function filterState(BaseFilter $filter, mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            // The filter's own form fields, as the panel would send them.
            return $value;
        }

        if ($filter instanceof SelectFilter) {
            $values = is_array($value) ? $value : explode(',', (string) self::scalar($value));
            $values = array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) self::scalar($item)), $values), static fn (string $item): bool => $item !== ''));

            return $filter->isMultiple() ? ['values' => $values] : ['value' => $values[0] ?? null];
        }

        $scalar = self::scalar($value);

        if ($filter instanceof TernaryFilter) {
            $bool = $scalar === null ? null : filter_var($scalar, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return ['value' => $bool === null ? null : ($bool ? '1' : '0')];
        }

        if ($filter instanceof Filter) {
            return ['isActive' => $scalar !== null && filter_var($scalar, FILTER_VALIDATE_BOOLEAN)];
        }

        return ['value' => $scalar];
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public static function invalid(string $parameter, string $detail, array $extensions = []): ProblemException
    {
        return ProblemException::make(400, $detail, 'invalid-parameter', ['parameter' => $parameter, ...$extensions]);
    }
}
