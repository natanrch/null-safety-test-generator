@forelse ($objects as $item)
    {{ $item->relation->name }}
@empty
    {{ $fallback->message }}
@endforelse
