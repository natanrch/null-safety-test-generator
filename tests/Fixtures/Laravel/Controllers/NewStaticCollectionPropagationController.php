<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\NewStaticCollectionPropagationService;

class NewStaticCollectionPropagationController
{
    public function localNew(Post $post)
    {
        $service = new NewStaticCollectionPropagationService();
        $service->inspect($post);

        return response()->json(['ok' => true]);
    }

    public function inlineNew(Post $post)
    {
        (new NewStaticCollectionPropagationService())->inspect($post);

        return response()->json(['ok' => true]);
    }

    public function constructorNew(Post $post)
    {
        $service = new NewStaticCollectionPropagationService($post);
        $service->inspectStoredPost();

        return response()->json(['ok' => true]);
    }

    public function inlineConstructorNew(Post $post)
    {
        (new NewStaticCollectionPropagationService($post))
            ->inspectStoredPost();

        return response()->json(['ok' => true]);
    }

    public function staticCall(Post $post)
    {
        $author = NewStaticCollectionPropagationService::author($post);
        NewStaticCollectionPropagationService::inspectAuthor($author);

        return response()->json(['ok' => true]);
    }

    public function collectionCallback(Post $post)
    {
        (new NewStaticCollectionPropagationService())
            ->inspectRelatedPosts($post);

        return response()->json(['ok' => true]);
    }

    public function arrowCollectionCallback(Post $post)
    {
        (new NewStaticCollectionPropagationService())
            ->inspectRelatedPostsWithArrow($post);

        return response()->json(['ok' => true]);
    }
}
