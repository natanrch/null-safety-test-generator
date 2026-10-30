<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\ModelPropagationService;

class GetServiceController
{
    public function localFirst()
    {
        $post = Post::query()->firstOrFail();
        $this->helper->handle($post);
        return response()->json(['ok' => true]);
    }

    public function localRequest(\Illuminate\Http\Request $request)
    {
        $post = Post::find($request->post_id);
        $this->helper->handle($post);
        return response()->json(['ok' => true]);
    }

    public function localCollection()
    {
        $posts = Post::all();
        $this->helper->handle($posts);
        return response()->json(['ok' => true]);
    }

    public function unusedLocal()
    {
        $post = Post::first();
        return response()->json(['ok' => true]);
    }

    public function localFiltered()
    {
        $post = Post::where('title', 'example')->first();
        $selected = $post;
        $this->helper->handle($selected);
        return response()->json(['ok' => true]);
    }

    public function localFindOrFail(\Illuminate\Http\Request $request)
    {
        $post = Post::findOrFail($request->post_id);
        $this->helper->handle($post);
        return response()->json(['ok' => true]);
    }

    public function __construct(
        private ModelPropagationService $helper
    ) {
    }

    public function viewResponse(Post $post)
    {
        $this->helper->handle($post);

        return view('posts.empty', ['post' => $post]);
    }

    public function jsonResponse(Post $post)
    {
        $this->helper->handle($post);

        return response()->json(['ok' => true]);
    }

    public function safeViewResponse(Post $post)
    {
        $this->helper->handleSafely($post);

        return view('posts.empty', ['post' => $post]);
    }
}
