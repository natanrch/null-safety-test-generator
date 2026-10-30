{{ $post->author === null || $post->author->profile === null ? '' : $post->author->profile->name }}
