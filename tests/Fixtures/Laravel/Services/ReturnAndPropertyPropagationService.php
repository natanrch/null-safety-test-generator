<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class ReturnAndPropertyPropagationService
{
    private Post $storedPost;

    public function author(Post $post): Author
    {
        return $post->author;
    }

    public function samePost(Post $post): Post
    {
        return $post;
    }

    public function forwardedAuthor(Post $post): Author
    {
        return $this->author($post);
    }

    public function inspectAuthor(Author $author): string
    {
        return $author->profile->name;
    }

    public function inspectReturnedAuthor(Post $post): string
    {
        $author = $this->forwardedAuthor($post);

        return $this->inspectAuthor($author);
    }

    public function storeAndInspect(Post $post): string
    {
        $this->storedPost = $post;

        return $this->inspectStored();
    }

    public function store(Post $post): void
    {
        $this->storedPost = $post;
    }

    public function inspectStored(): string
    {
        return $this->storedPost->author->profile->name;
    }

    public function storedPost(): Post
    {
        return $this->storedPost;
    }
}
