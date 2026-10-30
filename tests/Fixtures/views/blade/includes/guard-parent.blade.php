@if ($post->author)
    @include('includes.guard-forward', ['item' => $post->author])
@endif
@include('includes.bound', ['item' => $post->author])
