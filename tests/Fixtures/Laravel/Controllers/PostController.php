<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class PostController
{
    public function show(Post $post)
    {
        return view('posts.show', [
            'post' => $post,
        ]);
    }
}
