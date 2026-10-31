<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories\PostFactory;

class Post extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $appends = [
        'author_label',
        'hidden_author_posts',
    ];

    protected $hidden = [
        'hidden_author_posts',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function getAuthorLabelAttribute(): string
    {
        return $this->author->profile->name;
    }

    public function getHiddenAuthorPostsAttribute(): int
    {
        return $this->author->posts->count();
    }
}
