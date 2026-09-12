<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithCollections
{
    public function show(FakeObject $object)
    {
        $firstObject = AnotherFakeObject::query()
            ->where('active', true)
            ->first();

        $objects = AnotherFakeObject::query()
            ->where('active', true)
            ->get();

        $allObjects = AnotherFakeObject::all();

        return [
            'object' => $object,
            'firstObject' => $firstObject,
            'objects' => $objects,
            'allObjects' => $allObjects,
        ];
    }
}