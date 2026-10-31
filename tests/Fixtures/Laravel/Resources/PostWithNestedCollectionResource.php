<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostWithNestedCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'related_posts' => PostResource::collection(
                $this->author->posts
            ),
        ];
    }
}
