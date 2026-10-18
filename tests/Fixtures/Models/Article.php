<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    protected $table = 'api_articles';

    protected $guarded = [];

    protected $hidden = ['secret_note'];

    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'is_private' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<ApiUser, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(ApiUser::class, 'author_id');
    }
}
