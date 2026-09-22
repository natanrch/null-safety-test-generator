<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Author;

class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
