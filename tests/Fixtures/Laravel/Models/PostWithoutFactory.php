<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Model;

class PostWithoutFactory extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}
