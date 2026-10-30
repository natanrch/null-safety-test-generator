@if ($post->author && $post->author->profile)
{{ $post->author->profile->name }}
@endif
