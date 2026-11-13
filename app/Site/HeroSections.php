<?php

namespace App\Site;

use App\Models\HeroSection;

/** Active hero sections of the Main site (`null`) or of one Department, in display order. */
final class HeroSections
{
    /** @return list<array{id: int, title: ?string, content: string}> */
    public static function active(?int $departmentId): array
    {
        return HeroSection::query()
            ->where('department_id', $departmentId)->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'title', 'content'])
            ->map(fn (HeroSection $h) => ['id' => $h->id, 'title' => $h->title, 'content' => $h->content])->all();
    }
}
