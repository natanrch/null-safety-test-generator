@foreach ($objects as $item)
    @if (is_null($item->relation))
        Missing relation
    @elseif ($item->active)
        Active
    @endif

    {{ $item->date ? $item->date->format('d/m/Y') : '' }}
    {{ route('objects.show', $item->id) }}

    @if ($item->relation?->name)
        {{ $item->relation?->name }}
    @endif
@endforeach
