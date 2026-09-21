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
}
