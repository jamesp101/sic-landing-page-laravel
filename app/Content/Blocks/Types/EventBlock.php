<?php

declare(strict_types=1);

namespace App\Content\Blocks\Types;

use App\Content\Blocks\BlockType;
use App\Content\Blocks\EventDate;
use App\Content\Blocks\Limits;
use App\Content\Blocks\RenderContext;
use App\Content\Blocks\RenderKit;
use App\Content\Blocks\TextKit;
use App\Content\Blocks\Z;
use App\Content\Js;
use Closure;
use stdClass;

final class EventBlock extends BlockType
{
    public function meta(): array
    {
        return ['label' => 'Event details', 'group' => 'Content', 'description' => 'Date, time and location.'];
    }

    public function defaults(): array
    {
        return ['title' => '', 'start' => '', 'end' => '', 'location' => '', 'note' => ''];
    }

    public function schema(): array
    {
        return Z::object([
            'title' => Z::str(Limits::LABEL),
            'start' => Z::str(16),
            'end' => Z::str(16),
            'location' => Z::str(Limits::LABEL),
            'note' => Z::str(Limits::TEXT),
        ]);
    }

    public function rules(array $props, Closure $report): void
    {
        $start = EventDate::parseEventDate($props['start']);
        if ($start === null) {
            $report('Choose a valid start date.', ['start']);
        }
        if ($props['end'] !== '') {
            $end = EventDate::parseEventDate($props['end']);
            if ($end === null) {
                $report('Choose a valid end date.', ['end']);
            } elseif ($start !== null && EventDate::eventDateKey($end) < EventDate::eventDateKey($start)) {
                $report('The end must be after the start.', ['end']);
            }
        }
    }

    public function html(array $block, RenderContext $ctx): string
    {
        $p = $block['props'];
        $start = EventDate::parseEventDate($p['start']);
        $end = EventDate::parseEventDate($p['end']);
        if ($start === null && Js::blank($p['location']) && Js::blank($p['title'])) {
            return '';
        }
        $when = $start !== null
            ? '<div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">When</dt><dd class="mt-0.5 text-foreground">'.self::dateRange($start, $end !== null && EventDate::eventDateKey($end) >= EventDate::eventDateKey($start) ? $end : null).'</dd></div>'
            : '';
        $where = ! Js::blank($p['location'])
            ? '<div><dt class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Where</dt><dd class="mt-0.5 text-foreground"'.$ctx->field('location', 'plain').'>'.RenderKit::esc($p['location']).'</dd></div>'
            : '';
        $title = ! Js::blank($p['title']) ? '<p class="mb-3 text-lg font-bold text-primary"'.$ctx->field('title', 'plain').'>'.RenderKit::esc($p['title']).'</p>' : '';

        return '<div class="rounded-2xl border border-border bg-surface p-5 shadow-brand-sm"'.$ctx->hooks($block).'>'.$title.'<dl class="grid gap-4 text-sm sm:grid-cols-2">'.$when.$where.'</dl>'.RenderKit::paragraphsHtml($p['note'], 'mt-3 text-sm leading-relaxed text-muted-foreground', $ctx->field('note', 'rich')).'</div>';
    }

    public function text(stdClass $props): array
    {
        $start = EventDate::parseEventDate($props->start ?? null);

        return [
            TextKit::textOf($props->title ?? null),
            $start !== null ? EventDate::formatEventDay($start).' '.EventDate::formatEventTime($start) : '',
            TextKit::textOf($props->location ?? null),
            TextKit::richOf($props->note ?? null),
        ];
    }

    private static function time(array $date, string $text): string
    {
        return '<time datetime="'.RenderKit::esc($date['iso']).'">'.RenderKit::esc($text).'</time>';
    }

    private static function dateRange(array $start, ?array $end): string
    {
        $day = EventDate::formatEventDay($start);
        $startTime = EventDate::formatEventTime($start);
        $startText = $startTime !== '' ? "{$day}, {$startTime}" : $day;
        if ($end === null) {
            return self::time($start, $startText);
        }
        $sameDay = EventDate::formatEventDay($end) === $day;
        $endTime = EventDate::formatEventTime($end);
        if ($sameDay && $startTime !== '' && $endTime !== '') {
            return self::time($start, "{$day}, {$startTime}").' – '.self::time($end, $endTime);
        }
        if ($sameDay && $startTime === '' && $endTime === '') {
            return self::time($start, $day);
        }

        return self::time($start, $startText).' – '.self::time($end, EventDate::formatEventDay($end).($endTime !== '' ? ", {$endTime}" : ''));
    }
}
