@if (!$post->author || !$post->author->profile)
Missing
@else
{{ $post->author->profile->name }}
@endif
