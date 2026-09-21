<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Models;

class FakePost
{
    public function author()
    {
        return $this->belongsTo(FakeAuthor::class);
    }
}
