<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\ModelPropagationService;

class MethodInjectedServiceController
{
    public function process(
        Post $post,
        ModelPropagationService $service
    ) {
        $service->handle($post);

        return response()->noContent();
    }
}
