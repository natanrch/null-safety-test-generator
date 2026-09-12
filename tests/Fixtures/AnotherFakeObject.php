<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class AnotherFakeObject
{
    public static function find(int $id): self
    {
        return new self();
    }
}