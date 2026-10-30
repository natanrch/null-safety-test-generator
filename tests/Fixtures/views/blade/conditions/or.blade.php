@if ($post->author || $post->editor)
{{ $post->author->profile->name }}
@endif
