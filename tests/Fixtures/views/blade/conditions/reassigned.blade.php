@php $author = $post->author; @endphp
@if ($author)
@php $author = $post->editor; @endphp
{{ $author->profile->name }}
@endif
