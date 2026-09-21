<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Models;

class FakeAuthor
{
    public function profile()
    {
        return $this->hasOne(FakeProfile::class);
    }

    public function posts()
    {
        return $this->hasMany(FakePost::class);
    }
}
