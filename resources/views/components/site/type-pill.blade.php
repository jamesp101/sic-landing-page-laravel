@props(['type'])
@php
    $styles = [
        'article' => 'bg-status-article-bg text-status-article-fg',
        'event' => 'bg-status-event-bg text-status-event-fg',
        'announcement' => 'bg-status-announcement-bg text-status-announcement-fg',
    ];
    $labels = ['article' => 'Article', 'event' => 'Event', 'announcement' => 'Announcement'];
@endphp
<span @class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold', $styles[$type]])>
    {{ $labels[$type] }}
</span>
