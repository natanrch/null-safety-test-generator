@if ($post->author)
    {{ $post->author->profile->name }}
@endif
