@if (!$post->author || !$post->author->profile)
Missing
@else
@include('includes.bound', ['item' => $post->author])
@endif
