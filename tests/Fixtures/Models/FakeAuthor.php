<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Models;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Factories\FakeAuthorFactory;

class FakeAuthor
{
    public static function factory(): FakeAuthorFactory
    {
        return new FakeAuthorFactory();
    }

    public function profile()
    {
        return $this->hasOne(FakeProfile::class);
    }

    public function posts()
    {
        return $this->hasMany(FakePost::class);
    }

    public function orderedPosts()
    {
        return $this->hasMany(FakePost::class)
            ->orderBy('title');
    }

    public function relatedPosts()
    {
        return $this->belongsToMany(FakePost::class);
    }

    public function distantProfile()
    {
        return $this->hasOneThrough(FakeProfile::class, FakePost::class);
    }

    public function distantPosts()
    {
        return $this->hasManyThrough(FakePost::class, FakeProfile::class);
    }

    public function image()
    {
        return $this->morphOne(FakeProfile::class, 'imageable');
    }

    public function images()
    {
        return $this->morphMany(FakeProfile::class, 'imageable');
    }

    public function imageable()
    {
        return $this->morphTo();
    }

    public function tags()
    {
        return $this->morphToMany(FakePost::class, 'taggable');
    }

    public function taggedAuthors()
    {
        return $this->morphedByMany(FakePost::class, 'taggable');
    }

    public function parentAuthor()
    {
        return $this->hasOne(self::class);
    }
}
