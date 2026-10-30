@if ($post->author)
    {{ $post->author->profile->name }}
@endif
{{ $post->author->name }}
@isset($post->editor)
    {{ $post->editor->name }}
@endisset
@unless ($post->owner === null)
    {{ $post->owner->name }}
@endunless
@empty($post->reviewer)
    Nothing
@else
    {{ $post->reviewer->name }}
@endempty
