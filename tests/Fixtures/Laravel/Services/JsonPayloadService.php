<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class JsonPayloadService
{
    public function payload(Post $post): array
    {
        return [
            'profile' => $post->author->profile->name,
        ];
    }

    public function response(Post $post)
    {
        return response()->json([
            'profile' => $post->author->profile->name,
        ]);
    }
}
