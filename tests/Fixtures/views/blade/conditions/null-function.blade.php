@if (!is_null($post->author))
{{ $post->author->profile->name }}
@endif
