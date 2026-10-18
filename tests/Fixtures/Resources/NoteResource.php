<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitApi\Contracts\ExposesApi;
use AlfaSix\AlfakitApi\ResourceApi;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Note;

/**
 * Opts into the API itself and has no pages (the API uses stand-in pages).
 */
class NoteResource extends Resource implements ExposesApi
{
    protected static ?string $model = Note::class;

    protected static ?string $slug = 'notes';

    public static function api(ResourceApi $api): ResourceApi
    {
        return $api->fields(['id', 'body', 'shout' => static fn (Note $record): string => strtoupper((string) $record->getAttribute('body'))]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('body')->required()->minLength(3)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('body')->searchable()]);
    }
}
