<?php

namespace App\Site;

use App\Models\NavigationLink;

/**
 * The layout's one input (`<x-layouts.public :layout>`): ['navLinks' => list<nav link view>, 'departments' => list<department summary>].
 * Every page read computes it after its own row lookup, so an unknown slug costs one query.
 */
final class SiteLayout
{
    /** @return array{navLinks: list<array>, departments: list<array>} */
    public static function read(): array
    {
        return ['navLinks' => self::navLinks(), 'departments' => DepartmentPages::summaries()];
    }

    /**
     * Active Navigation links with their active children: one flat query, nested in memory.
     *
     * @return list<array{id: int, label: string, url: string, children: list<array>}>
     */
    public static function navLinks(): array
    {
        $rows = NavigationLink::query()
            ->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'label', 'url', 'parent_id']);

        $roots = [];
        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                $roots[$row->id] = ['id' => $row->id, 'label' => $row->label, 'url' => $row->url, 'children' => []];
            }
        }
        foreach ($rows as $row) {
            if ($row->parent_id !== null && isset($roots[$row->parent_id])) {
                $roots[$row->parent_id]['children'][] = ['id' => $row->id, 'label' => $row->label, 'url' => $row->url, 'children' => []];
            }
        }

        return array_values($roots);
    }
}
