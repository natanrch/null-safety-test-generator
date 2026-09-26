@forelse ($objects as $item)
    {{ $item->name }}
@empty
    No objects found.
@endforelse
