<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Resources;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\DeleteBulkAction;
use AlfaSix\Alfakit\Forms\Components\DateTimePicker;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Forms\Components\Toggle;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;
use Illuminate\Support\Enumerable;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $slug = 'articles';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(120),
            Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft')->required(),
            Textarea::make('body'),
            Toggle::make('is_featured'),
            Toggle::make('is_private'),
            DateTimePicker::make('published_at'),
            TextInput::make('secret_note'),
            FileUpload::make('attachment'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status'),
                TextColumn::make('published_at')->sortable(),
                TextColumn::make('author.name'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
                TernaryFilter::make('is_featured'),
            ])
            ->bulkActions([
                BulkAction::make('publish')
                    ->action(static fn (Enumerable $records) => $records->each(static fn (Article $article) => $article->update(['status' => 'published']))),
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ArticleResource\Pages\ListArticles::route('/'),
            'create' => ArticleResource\Pages\CreateArticle::route('/create'),
            'edit' => ArticleResource\Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
