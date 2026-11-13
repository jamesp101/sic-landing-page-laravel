<?php

namespace App\Admin;

use App\Models\HeroSection;
use App\Models\Post;
use App\Storage\Storage;

/**
 * The saved values an edit form island starts from, in the camelCase names its `post` / `section` prop declares
 * (`PostValues` in `PostForm.svelte`, `HeroValues` in `HeroSectionForm.svelte`; `FormValuesParityTest` fails when
 * the keys differ). The server owns the formatting: instants as ISO strings, file keys as public URLs, a missing
 * Hero section title as ''. The Department is not here: it comes from `DepartmentAccess::departmentField`.
 */
final class FormValues
{
    /**
     * @return array{id: int, title: string, slug: string, content: string, type: string, startDate: ?string, endDate: ?string, isFeatured: bool, hideAuthor: bool, highlightImageUrl: ?string}
     */
    public static function post(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'content' => $post->content,
            'type' => $post->type,
            'startDate' => $post->start_date?->toISOString(),
            'endDate' => $post->end_date?->toISOString(),
            'isFeatured' => $post->is_featured,
            'hideAuthor' => $post->hide_author,
            'highlightImageUrl' => Storage::publicUrl($post->highlight_image_path),
        ];
    }

    /**
     * @return array{id: int, title: string, content: string, order: int, isActive: bool}
     */
    public static function heroSection(HeroSection $section): array
    {
        return [
            'id' => $section->id,
            'title' => $section->title ?? '',
            'content' => $section->content,
            'order' => $section->order,
            'isActive' => $section->is_active,
        ];
    }
}
