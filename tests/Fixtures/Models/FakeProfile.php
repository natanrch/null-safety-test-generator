<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures\Models;

use Natan\NullSafetyTestGenerator\Tests\Fixtures\Factories\FakeProfileFactory;

class FakeProfile
{
    public static function factory(): FakeProfileFactory
    {
        return new FakeProfileFactory();
    }
}
