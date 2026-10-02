<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\ModelPropagationService;

class TypedPropertyServiceController
{
    private ModelPropagationService $service;

    public function process(Post $post)
    {
        $this->service->handle($post);

        return response()->noContent();
    }
}
