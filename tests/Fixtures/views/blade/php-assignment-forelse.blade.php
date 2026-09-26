@php
    $relatedPosts = $post->author->posts;
@endphp

@forelse ($relatedPosts as $relatedPost)
    {{ $relatedPost->title }}
@empty
    No related posts.
@endforelse
