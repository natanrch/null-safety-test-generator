<?php

namespace Natan\NullSafetyTestGenerator\Tests\Feature;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;
use Natan\NullSafetyTestGenerator\Tests\TestCase;

class TestbenchIntegrationTest extends TestCase
{
    public function test_it_boots_laravel_with_routes_models_and_factories(): void
    {
        $author = Author::factory()
            ->has(Profile::factory(), 'profile')
            ->create();

        $post = Post::factory()
            ->for($author, 'author')
            ->create();

        $response = $this->get(
            route('posts.show', ['post' => $post])
        );

        $response->assertOk();
        $response->assertSee($post->title);
        $response->assertSee($author->profile->name);
    }
}
