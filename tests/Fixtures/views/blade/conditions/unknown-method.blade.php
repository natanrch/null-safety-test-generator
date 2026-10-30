@if ($post->hasAuthor())
{{ $post->author->profile->name }}
@endif
