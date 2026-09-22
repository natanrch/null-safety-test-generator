<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Profile;

class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    public function definition(): array
    {
        return [
            'author_id' => Author::factory(),
            'name' => fake()->name(),
        ];
    }
}
