<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithChainedMethods
{
    public function show(FakeObject $object)
    {
        $firstObject = AnotherFakeObject::query()->first();

        $secondObject = AnotherFakeObject::query()
            ->where('active', true)
            ->first();

        return [
            'object' => $object,
            'firstObject' => $firstObject,
            'secondObject' => $secondObject,
        ];
    }
}