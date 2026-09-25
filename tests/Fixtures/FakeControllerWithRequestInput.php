<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

use Illuminate\Http\Request;

class FakeControllerWithRequestInput
{
    public function create(Request $request)
    {
        $object = AnotherFakeObject::find($request->object_id);

        return view('fixtures.objects.request', [
            'object' => $object,
        ]);
    }

    public function createOrFail(Request $request)
    {
        $object = AnotherFakeObject::findOrFail($request->object_id);

        return view('fixtures.objects.request', [
            'object' => $object,
        ]);
    }
}
