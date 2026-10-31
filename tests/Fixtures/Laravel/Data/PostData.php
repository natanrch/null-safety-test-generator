<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Data;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class PostData
{
    public function __construct(
        public string $authorName
    ) {
    }

    public static function from(Post $post): self
    {
        return new self(
            $post->author->profile->name
        );
    }
}
