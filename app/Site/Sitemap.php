<?php

namespace App\Site;

use App\Models\Department;
use App\Models\Post;
use App\Storage\Storage;
use Carbon\Carbon;

/** What `/sitemap.xml` lists. */
final class Sitemap
{
    /**
     * @return array{departments: list<array{slug: string, updatedAt: Carbon}>, posts: list<array{slug: string, updatedAt: Carbon, imageUrl: ?string, title: string}>}
     */
    public static function read(): array
    {
        $departments = Department::query()->orderBy('name')->get(['slug', 'updated_at'])
            ->map(fn (Department $d) => ['slug' => $d->slug, 'updatedAt' => $d->updated_at])->all();
        $posts = Post::query()->orderByDesc('created_at')->get(['slug', 'updated_at', 'highlight_image_path', 'title'])
            ->map(fn (Post $p) => [
                'slug' => $p->slug,
                'updatedAt' => $p->updated_at,
                'imageUrl' => Storage::publicUrl($p->highlight_image_path),
                'title' => $p->title,
            ])->all();

        return ['departments' => $departments, 'posts' => $posts];
    }
}
