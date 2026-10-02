<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'author_name' => $this->author->name,
        ];
    }
}
