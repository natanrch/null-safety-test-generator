<h1>{{ $object->name }}</h1>

<p>{{ $otherObject->name }}</p>

@foreach ($objects as $item)
    <p>{{ $item->name }}</p>
@endforeach
