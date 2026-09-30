<?php

namespace Tests\Feature\Generated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsShowNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_posts_show_does_not_fail_when_post_author_is_null(): void
    {
        $post = \Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post::factory()->state(['author_id' => null])->create();

        $response = $this->get(
            route('posts.show', ['post' => $post])
        );

        $this->assertLessThan(500, $response->status());
        $this->assertNotSame(404, $response->status());
    }

    public function test_posts_show_does_not_fail_when_post_author_profile_is_null(): void
    {
        $post = \Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post::factory()->for(\Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author::factory(), 'author')->create();

        $response = $this->get(
            route('posts.show', ['post' => $post])
        );

        $this->assertLessThan(500, $response->status());
        $this->assertNotSame(404, $response->status());
    }
}
