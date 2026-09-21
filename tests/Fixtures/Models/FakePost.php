<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Models;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Factories\FakePostFactory;

class FakePost
{
    public static function factory(): FakePostFactory
    {
        return new FakePostFactory();
    }

    public function author()
    {
        return $this->belongsTo(FakeAuthor::class);
    }
}
