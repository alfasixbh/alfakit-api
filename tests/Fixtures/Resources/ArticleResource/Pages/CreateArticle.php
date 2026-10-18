<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /** @var array<int, string> */
    public static array $calledHooks = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = $this->getPanel()->auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        static::$calledHooks[] = 'afterCreate:'.$this->getCreatedRecord()?->getKey();
    }
}
