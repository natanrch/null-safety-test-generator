<?php

namespace Natan\NullSafetyTestGenerator\Tests\Fixtures;

class FakeControllerWithPagination
{
    public function index()
    {
        $paginatedObjects = AnotherFakeObject::orderBy('created_at', 'desc')
            ->orderByRaw('CAST(position AS INTEGER) DESC')
            ->paginate(10);

        $simplePaginatedObjects = AnotherFakeObject::query()
            ->where('active', true)
            ->simplePaginate(10);

        $cursorPaginatedObjects = AnotherFakeObject::query()
            ->orderBy('id')
            ->cursorPaginate(10);

        return view('objects.index', [
            'paginatedObjects' => $paginatedObjects,
            'simplePaginatedObjects' => $simplePaginatedObjects,
            'cursorPaginatedObjects' => $cursorPaginatedObjects,
        ]);
    }
}
