<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models\Category;

class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'description' => fake()->sentence(),
        ];
    }
}
