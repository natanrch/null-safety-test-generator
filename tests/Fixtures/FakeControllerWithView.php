<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithView
{
    public function show(FakeObject $object)
    {
        $otherObject = AnotherFakeObject::findOrFail(1);

        $objects = AnotherFakeObject::query()
            ->where('active', true)
            ->get();

        $unusedObject = AnotherFakeObject::find(2);

        return view('fixtures.objects.show', [
            'object' => $object,
            'otherObject' => $otherObject,
            'objects' => $objects,
        ]);
    }
}
