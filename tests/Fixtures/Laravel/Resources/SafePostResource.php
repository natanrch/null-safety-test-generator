<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafePostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'profile_name' => $this->author?->profile?->name,
        ];
    }
}
