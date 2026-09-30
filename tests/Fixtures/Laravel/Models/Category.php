<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories\CategoryFactory;

class Category extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }
}
