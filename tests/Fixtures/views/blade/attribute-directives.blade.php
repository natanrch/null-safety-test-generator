<div @class(['active' => $post->author->active]) @style(['color:red' => $post->editor->active])>
<input @checked($post->reviewer->active) @selected($post->owner->active)
 @disabled($post->manager->active) @readonly($post->creator->active) @required($post->publisher->active)>
</div>
