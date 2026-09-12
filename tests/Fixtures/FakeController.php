<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeController
{
    public function show(FakeObject $object)
    {
        return [
            'Object' => $object,
        ];
    }
}