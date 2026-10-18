<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /** @var array<int, string> */
    public static array $calledHooks = [];

    protected function afterSave(): void
    {
        static::$calledHooks[] = 'afterSave:'.$this->getRecord()?->getKey();
    }
}
