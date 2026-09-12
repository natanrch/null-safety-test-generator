<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeController
{
    public function show(FakeObject $object)
    {
        $otherObject = AnotherFakeObject::find(1);

        return [
            'object' => $object,
            'otherObject' => $otherObject,
        ];
    }
}