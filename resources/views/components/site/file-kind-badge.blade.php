{{-- File extension shown as text so colour is never the only cue. --}}
@props(['kind', 'extension'])
@php
    $styles = [
        'pdf' => 'bg-file-pdf-bg text-file-pdf-fg',
        'doc' => 'bg-file-doc-bg text-file-doc-fg',
        'sheet' => 'bg-file-sheet-bg text-file-sheet-fg',
        'image' => 'bg-file-image-bg text-file-image-fg',
        'archive' => 'bg-file-archive-bg text-file-archive-fg',
        'other' => 'bg-file-other-bg text-file-other-fg',
    ];
@endphp
<span @class(['inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xs font-bold uppercase', $styles[$kind]]) aria-hidden="true">
    {{ mb_substr($extension, 0, 4) ?: 'file' }}
</span>
