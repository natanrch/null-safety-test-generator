<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Category;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\CategoryWithoutFactory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Requests\StorePostRequest;

class PostController
{
    public function emptyAction()
    {
        // This resource action has not been implemented yet.
    }

    public function show(Post $post)
    {
        return view('posts.show', [
            'post' => $post,
        ]);
    }

    public function showRedacaoFinal(Post $redacaoFinal)
    {
        return view('posts.show', [
            'post' => $redacaoFinal,
        ]);
    }

    public function archive(int $year, string $slug)
    {
        return view('posts.show');
    }

    public function store(Request $request)
    {
        return response()->noContent();
    }

    public function update(Request $request, Post $post)
    {
        return response()->noContent();
    }

    public function patch(Request $request, Post $post)
    {
        return response()->noContent();
    }

    public function destroy(Request $request, Post $post)
    {
        $request->validate([
            'reason' => ['required', 'string'],
        ]);

        return response()->noContent();
    }

    public function destroyWithAuthor(Post $post)
    {
        strtoupper($post->author->name);

        return response()->noContent();
    }

    public function storeInline(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'active' => ['required', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'category_id' => ['required', 'exists:categories,id'],
            'tags' => ['array'],
        ]);

        $category = Category::findOrFail($request->category_id);

        return response()->noContent();
    }

    public function storeFormRequest(StorePostRequest $request)
    {
        return response()->noContent();
    }

    public function storeWithValidator(Request $request)
    {
        Validator::make($request->all(), [
            'quantity' => ['required', 'integer'],
            'accepted' => ['accepted'],
            'identifier' => ['uuid'],
        ])->validate();

        return response()->noContent();
    }

    public function storeWithExistingCategory(Request $request)
    {
        $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
        ]);

        $category = Category::findOrFail($request->category_id);
        strtoupper($category->description);

        return response()->noContent();
    }

    public function storeWithExistingCategoryWithoutFactory(Request $request)
    {
        $request->validate([
            'category_id' => ['required', 'exists:categories_without_factories,id'],
        ]);

        $category = CategoryWithoutFactory::findOrFail($request->category_id);

        return response()->noContent();
    }

    public function processAuthorProfile(Post $post)
    {
        $author = $post->author;
        strtoupper($author->profile->name);

        return response()->noContent();
    }

    public function processId(Post $post)
    {
        $id = $post->id;

        return response()->json(['id' => $id]);
    }

    public function processAuthorId(Post $post)
    {
        $id = $post->author->id;

        return response()->json(['id' => $id]);
    }

    public function processSensitiveTitle(Post $post)
    {
        $title = strtoupper($post->title);

        return response()->json(['title' => $title]);
    }

    public function processOptionalAuthor(Post $post)
    {
        return optional($post->author)->name;
    }

    public function processCoalescedProfile(Post $post)
    {
        return $post->author->profile->name ?? 'unknown';
    }

    public function processGuardedAuthor(Post $post)
    {
        if ($post->author) {
            return $post->author->name;
        }

        return null;
    }

    public function processComparedGuard(Post $post)
    {
        if ($post->author !== null) {
            return $post->author->name;
        }

        return null;
    }

    public function processTernaryGuard(Post $post)
    {
        return $post->author
            ? $post->author->name
            : null;
    }

    public function processIssetGuard(Post $post)
    {
        return isset($post->author->name);
    }

    public function processEmptyGuard(Post $post)
    {
        return empty($post->author->name);
    }

    public function processNullsafeAuthor(Post $post)
    {
        return $post->author?->profile?->name;
    }

    public function processComparedTitle(Post $post)
    {
        return $post->title === 'published';
    }
}
