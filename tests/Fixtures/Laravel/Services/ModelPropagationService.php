<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class ModelPropagationService
{
    public function handle(Post $post): string
    {
        return $this->inspectAuthor($post);
    }

    private function inspectAuthor(Post $post): string
    {
        return strtoupper($post->author->profile->name);
    }
}
