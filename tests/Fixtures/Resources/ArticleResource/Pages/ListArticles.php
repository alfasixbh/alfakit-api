<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitApi\Tests\Fixtures\Resources\ArticleResource;

class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;
}
