<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Post;

class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'author_id' => Author::factory(),
            'title' => fake()->sentence(),
        ];
    }
}
