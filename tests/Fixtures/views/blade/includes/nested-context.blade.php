@php $selected = $post->author; @endphp
@includeIf('includes.forward', ['item' => $selected])
{{-- @include('includes.bound', ['item' => $ignored]) --}}
