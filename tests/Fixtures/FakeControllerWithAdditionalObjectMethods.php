<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithAdditionalObjectMethods
{
    public function show()
    {
        $foundObject = AnotherFakeObject::findOrFail(1);

        $firstObject = AnotherFakeObject::query()
            ->where('active', true)
            ->firstOrFail();

        $soleObject = AnotherFakeObject::query()->sole();

        $createdObject = AnotherFakeObject::create([
            'active' => true,
        ]);

        $firstOrCreatedObject = AnotherFakeObject::query()
            ->firstOrCreate(['active' => true]);

        $firstOrNewObject = AnotherFakeObject::query()
            ->firstOrNew(['active' => true]);

        return [
            'foundObject' => $foundObject,
            'firstObject' => $firstObject,
            'soleObject' => $soleObject,
            'createdObject' => $createdObject,
            'firstOrCreatedObject' => $firstOrCreatedObject,
            'firstOrNewObject' => $firstOrNewObject,
        ];
    }
}
