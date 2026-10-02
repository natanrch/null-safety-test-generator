<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NestedPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'author' => new AuthorResource($this->author),
        ];
    }
}
