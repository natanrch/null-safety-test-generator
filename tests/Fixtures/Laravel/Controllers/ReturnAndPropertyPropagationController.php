<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\ReturnAndPropertyPropagationService;

class ReturnAndPropertyPropagationController
{
    public function __construct(
        private ReturnAndPropertyPropagationService $service
    ) {
    }

    public function returned(Post $post)
    {
        $author = $this->service->forwardedAuthor($post);
        $this->service->inspectAuthor($author);

        return response()->json(['ok' => true]);
    }

    public function returnedInternally(Post $post)
    {
        $this->service->inspectReturnedAuthor($post);

        return response()->json(['ok' => true]);
    }

    public function storedInternally(Post $post)
    {
        $this->service->storeAndInspect($post);

        return response()->json(['ok' => true]);
    }

    public function storedAcrossCalls(Post $post)
    {
        $this->service->store($post);
        $this->service->inspectStored();

        return response()->json(['ok' => true]);
    }

    public function returnedFromProperty(Post $post)
    {
        $this->service->store($post);
        $stored = $this->service->storedPost();
        $this->service->storeAndInspect($stored);

        return response()->json(['ok' => true]);
    }

    public function guardedReturn(Post $post)
    {
        if ($post->author) {
            $author = $this->service->author($post);
            $this->service->inspectAuthor($author);
        }

        return response()->json(['ok' => true]);
    }

    public function guardedStored(Post $post)
    {
        if ($post->author) {
            $this->service->store($post);
            $this->service->inspectStored();
        }

        return response()->json(['ok' => true]);
    }

    public function returnedModel(Post $post)
    {
        $samePost = $this->service->samePost($post);
        $this->service->storeAndInspect($samePost);

        return response()->json(['ok' => true]);
    }

    public function directlyChainedReturn(Post $post)
    {
        return response()->json([
            'name' => $this->service->author($post)->profile->name,
        ]);
    }
}
