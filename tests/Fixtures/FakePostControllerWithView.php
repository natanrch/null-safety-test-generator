<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Models\FakePost;

class FakePostControllerWithView
{
    public function show(FakePost $post)
    {
        return view('fixtures.posts.show', [
            'post' => $post,
        ]);
    }
}
