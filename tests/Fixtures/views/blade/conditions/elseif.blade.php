@if ($post->author === null)
Missing
@elseif ($post->author->profile === null)
Missing profile
@else
{{ $post->author->profile->name }}
@endif
