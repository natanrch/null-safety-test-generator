<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class NewStaticCollectionPropagationService
{
    public function __construct(private ?Post $storedPost = null)
    {
    }

    public function inspect(Post $post): string
    {
        return $post->author->profile->name;
    }

    public static function author(Post $post): Author
    {
        return $post->author;
    }

    public static function inspectAuthor(Author $author): string
    {
        return $author->profile->name;
    }

    public function inspectRelatedPosts(Post $post): void
    {
        $post->author->posts->each(function (Post $relatedPost): void {
            $relatedPost->author->profile->name;
        });
    }

    public function inspectRelatedPostsWithArrow(Post $post): void
    {
        $post->author->posts
            ->filter(fn (Post $relatedPost): bool => $relatedPost->author !== null)
            ->map(fn (Post $relatedPost): ?string => $relatedPost->author->profile->name);
    }

    public function inspectStoredPost(): string
    {
        return $this->storedPost->author->profile->name;
    }
}
