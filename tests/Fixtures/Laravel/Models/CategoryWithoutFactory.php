<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryWithoutFactory extends Model
{
    protected $table = 'categories_without_factories';

    protected $guarded = [];
}
