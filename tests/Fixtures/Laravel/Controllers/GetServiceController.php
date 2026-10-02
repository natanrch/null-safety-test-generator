<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\ModelPropagationService;

class GetServiceController
{
    public function __construct(
        private ModelPropagationService $helper
    ) {
    }

    public function viewResponse(Post $post)
    {
        $this->helper->handle($post);

        return view('posts.empty', ['post' => $post]);
    }

    public function jsonResponse(Post $post)
    {
        $this->helper->handle($post);

        return response()->json(['ok' => true]);
    }

    public function safeViewResponse(Post $post)
    {
        $this->helper->handleSafely($post);

        return view('posts.empty', ['post' => $post]);
    }
}
