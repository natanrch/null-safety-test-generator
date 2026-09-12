<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithPluckedCollection
{
    public function show()
    {
        $values = AnotherFakeObject::query()
            ->where('active', true)
            ->pluck('name');

        return [
            'values' => $values,
        ];
    }
}
