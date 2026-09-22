<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Natan\NullSafetyTestGenerator\Tests\Fixtures\Laravel\Factories\AuthorFactory;

class Author extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function newFactory(): AuthorFactory
    {
        return AuthorFactory::new();
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
