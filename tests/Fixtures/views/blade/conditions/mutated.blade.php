@if ($post->author)
    @php $post->author = null; @endphp
    {{ $post->author->profile->name }}
@endif
