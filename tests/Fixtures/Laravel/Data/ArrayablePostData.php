<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Data;

use Illuminate\Contracts\Support\Arrayable;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

/** @implements Arrayable<string, mixed> */
class ArrayablePostData implements Arrayable
{
    private Post $post;

    public function __construct(Post $post)
    {
        $this->post = $post;
    }

    public function toArray(): array
    {
        return [
            'author_name' => $this->post->author->profile->name,
        ];
    }
}
