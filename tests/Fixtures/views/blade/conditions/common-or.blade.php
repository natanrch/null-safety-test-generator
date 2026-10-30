@if (($post->author && $first) || ($post->author && $second))
{{ $post->author->profile->name }}
@endif
