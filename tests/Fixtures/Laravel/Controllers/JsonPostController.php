<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Illuminate\Http\Request;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\NestedPostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\PostResource;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Resources\SafePostResource;

class JsonPostController
{
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

    public function directModel(Post $post)
    {
        return $post;
    }

    public function directCollection()
    {
        $posts = Post::all();

        return $posts;
    }

    public function resource(Post $post): PostResource
    {
        return new PostResource($post);
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
}
