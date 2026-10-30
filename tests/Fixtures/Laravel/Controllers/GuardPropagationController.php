<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Controllers;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Services\GuardPropagationService;

class GuardPropagationController
{
    public function __construct(private GuardPropagationService $service) {}

    public function guarded(Post $post)
    {
        if ($post->author !== null) {
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function partial(Post $post)
    {
        if ($post->author) {
            $this->service->profile($post);
        }
        return response()->json(['ok' => true]);
    }

    public function mixed(Post $post)
    {
        if ($post->author) {
            $this->service->name($post);
        }
        $this->service->name($post);
        return response()->json(['ok' => true]);
    }

    public function mixedReverse(Post $post)
    {
        $this->service->name($post);
        if ($post->author) {
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function related(Post $post)
    {
        if ($post->author) {
            $this->service->related($post->author);
        }
        return response()->json(['ok' => true]);
    }

    public function negativeBranch(Post $post)
    {
        if (!$post->author || !$post->author->profile) {
            return response()->json(['missing' => true]);
        } else {
            $this->service->profile($post);
        }
        return response()->json(['ok' => true]);
    }

    public function disjunction(Post $post, bool $enabled = true)
    {
        if ($post->author || $enabled) {
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function reassigned(Post $post, Post $other)
    {
        if ($post->author) {
            $post = $other;
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function mutation(Post $post)
    {
        if ($post->author) {
            $post->author = null;
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function serviceMutation(Post $post)
    {
        if ($post->author) {
            $this->service->mutateThenRead($post);
        }
        return response()->json(['ok' => true]);
    }

    public function callerAfterMutation(Post $post)
    {
        if ($post->author) {
            $this->service->mutate($post);
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function pureCalls(Post $post)
    {
        if ($post->author) {
            $this->service->name($post);
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }

    public function unknownMutation(Post $post)
    {
        if ($post->author) {
            unknown_mutator($post);
            $this->service->name($post);
        }
        return response()->json(['ok' => true]);
    }
}
