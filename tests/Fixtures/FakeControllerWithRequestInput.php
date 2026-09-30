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

    public function createFromInput(Request $request)
    {
        $object = AnotherFakeObject::find(
            $request->input('object_id')
        );

        return view('fixtures.objects.request', [
            'object' => $object,
        ]);
    }

    public function filtered(Request $request)
    {
        $status = $request->string('status');
        $page = $request->integer('page');
        $active = $request->boolean('active');
        $search = $request->query('search');
        $object = AnotherFakeObject::first();

        return view('fixtures.objects.request', [
            'object' => $object,
        ]);
    }

    public function withCollection(Request $request)
    {
        $object = AnotherFakeObject::find($request->object_id);
        $objects = AnotherFakeObject::all();

        return view('fixtures.objects.forelse', [
            'object' => $object,
            'objects' => $objects,
        ]);
    }
}
