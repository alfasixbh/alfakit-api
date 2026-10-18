<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Policies;

use AlfaSix\AlfakitApi\Tests\Fixtures\Models\ApiUser;
use AlfaSix\AlfakitApi\Tests\Fixtures\Models\Article;

class ArticlePolicy
{
    public function viewAny(ApiUser $user): bool
    {
        return true;
    }

    public function view(ApiUser $user, Article $article): bool
    {
        return $user->getAttribute('api_admin') || ! $article->getAttribute('is_private');
    }

    public function create(ApiUser $user): bool
    {
        return true;
    }

    public function update(ApiUser $user, Article $article): bool
    {
        return $user->getAttribute('api_admin') || (int) $article->getAttribute('author_id') === (int) $user->getKey();
    }

    public function delete(ApiUser $user, Article $article): bool
    {
        return (bool) $user->getAttribute('api_admin');
    }

    public function deleteAny(ApiUser $user): bool
    {
        return (bool) $user->getAttribute('api_admin');
    }
}
