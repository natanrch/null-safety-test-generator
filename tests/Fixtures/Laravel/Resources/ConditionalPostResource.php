<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConditionalPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'profile' => $this->when(
                $this->author !== null,
                fn (): ?string => $this->author->profile->name
            ),
            'merged_profile' => $this->mergeWhen(
                $this->author !== null,
                fn (): array => [
                    'name' => $this->author->profile->name,
                ]
            ),
            'fallback_profile' => $this->when(
                $this->author === null,
                'No author',
                fn (): ?string => $this->author->profile->name
            ),
            'loaded_author' => new AuthorResource(
                $this->whenLoaded('author')
            ),
            'nullable_author' => new AuthorResource(
                $this->whenNotNull($this->author)
            ),
            'title' => $this->whenHas('title'),
            'posts_count' => $this->whenCounted('posts'),
            'posts_average' => $this->whenAggregated(
                'posts',
                'id',
                'avg'
            ),
        ];
    }
}
