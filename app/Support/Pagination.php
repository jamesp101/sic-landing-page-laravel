<?php

declare(strict_types=1);

namespace App\Support;

final class Pagination
{
    /** 1-based page number from a `?page=` value; anything but a positive integer is page 1. */
    public static function parsePage(?string $value): int
    {
        if ($value === null || $value === '' || ! preg_match('/^\d{1,6}$/D', $value)) {
            return 1;
        }

        return max(1, (int) $value);
    }

    /**
     * Page links to render for `$current` of `$count` pages: the first and last page, the current page
     * and its neighbours, with `null` where pages are skipped. A gap never hides a single page.
     *
     * @return list<int|null>
     */
    public static function pageWindow(int $current, int $count): array
    {
        $shown = [1 => true, $count => true, $current - 1 => true, $current => true, $current + 1 => true];
        $pages = [];
        for ($page = 1; $page <= $count; $page++) {
            // Show a lone skipped page instead of an ellipsis that would stand for just that page.
            $bridging = ! isset($shown[$page]) && isset($shown[$page - 1]) && isset($shown[$page + 1]);
            if (isset($shown[$page]) || $bridging) {
                $pages[] = $page;
            } elseif ($pages === [] || end($pages) !== null) {
                $pages[] = null;
            }
        }

        return $pages;
    }
}
