<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class ApiPostController
{
    public function show(Post $post): array
    {
        return [
            'id' => $post->getKey(),
            'title' => $post->title,
        ];
    }
}
