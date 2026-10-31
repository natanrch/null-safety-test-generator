<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Illuminate\Http\Request;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\NestedPostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\ConditionalPostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\PostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\PostWithNestedCollectionResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\SafePostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\JsonPayloadService;

class JsonPostController
{
    public function __construct(
        private JsonPayloadService $payloadService
    ) {
    }

    public function jsonResponse(Post $post)
    {
        return response()->json([
            'profile' => $post->author->profile->name,
        ]);
    }

    public function directArray(Post $post): array
    {
        return [
            'profile' => $post->author->profile->name,
        ];
    }

    public function assignedPayload(Post $post)
    {
        $payload = [
            'profile' => $post->author->profile->name,
        ];

        return response()->json($payload);
    }

    public function helperPayload(Post $post)
    {
        return response()->json($this->makePayload($post));
    }

    public function servicePayload(Post $post)
    {
        return response()->json(
            $this->payloadService->payload($post)
        );
    }

    public function helperResponse(Post $post)
    {
        return $this->makeResponse($post);
    }

    public function serviceResponse(Post $post)
    {
        return $this->payloadService->response($post);
    }

    public function localServiceResponse(Post $post)
    {
        $service = new JsonPayloadService();

        return $service->response($post);
    }

    public function directModel(Post $post)
    {
        return $post;
    }

    public function directCollection()
    {
        $posts = Post::all();

        return $posts;
    }

    public function collectionUsingNullableRoot()
    {
        $author = Author::where('name', 'active')->first();
        $posts = Post::where('author_id', $author->id)->get();
        $posts = $posts->map(
            static fn (Post $post): Post => $post
        );

        return $posts;
    }

    public function collectionUsingFindRoot()
    {
        $author = Author::find(1);
        $posts = Post::where('author_id', $author->id)->get();

        return $posts;
    }

    public function collectionUsingFirstWhereRoot()
    {
        $author = Author::firstWhere('name', 'active');
        $posts = Post::where('author_id', $author->id)->get();

        return $posts;
    }

    public function collectionUsingRequiredRoot()
    {
        $author = Author::where('name', 'active')->firstOrFail();
        $posts = Post::where('author_id', $author->id)->get();

        return $posts;
    }

    public function collectionWithUnusedNullableRoot()
    {
        $author = Author::where('name', 'active')->first();
        $posts = Post::all();

        return $posts;
    }

    public function resource(Post $post): PostResource
    {
        return new PostResource($post);
    }

    public function resourceMake(Post $post): PostResource
    {
        return PostResource::make($post);
    }

    public function conditionalResource(
        Post $post
    ): ConditionalPostResource {
        return ConditionalPostResource::make($post);
    }

    public function resourceCollection()
    {
        $posts = Post::all();

        return PostResource::collection($posts);
    }

    public function nestedResource(Post $post): NestedPostResource
    {
        return new NestedPostResource($post);
    }

    public function nestedResourceCollection(
        Post $post
    ): PostWithNestedCollectionResource {
        return PostWithNestedCollectionResource::make($post);
    }

    public function safeResource(Post $post): SafePostResource
    {
        return new SafePostResource($post);
    }

    public function mixed(Request $request, Post $post)
    {
        if (! $request->expectsJson()) {
            return view('posts.show', ['post' => $post]);
        }

        return response()->json([
            'profile' => $post->author->profile->name,
        ]);
    }

    private function makePayload(Post $post): array
    {
        return [
            'profile' => $post->author->profile->name,
        ];
    }

    private function makeResponse(Post $post)
    {
        return response()->json($this->makePayload($post));
    }
}
