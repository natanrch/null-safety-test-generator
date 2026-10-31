<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Data;

use JsonSerializable;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class SerializablePostData implements JsonSerializable
{
    public function __construct(private Post $post)
    {
    }

    public function jsonSerialize(): array
    {
        return [
            'author_name' => $this->post->author->profile->name,
        ];
    }
}
