<?php

namespace App\Site;

use App\Content\Content;
use App\Models\Post;
use App\Storage\Storage;
use App\Storage\Variants;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public Post reads: list, page, related Posts and the Post summary with its Excerpt. A Hidden post is left out of
 * every list (`listed()`), but its own page still opens.
 */
final class Posts
{
    private const COLUMNS = [
        'id', 'title', 'slug', 'type', 'search_text', 'highlight_image_path', 'start_date', 'end_date',
        'is_featured', 'created_at', 'updated_at', 'department_id',
    ];

    /**
     * The newest Posts, optionally of one Department.
     *
     * @return list<array>
     */
    public static function newest(int $limit, ?int $departmentId = null): array
    {
        $query = self::query();
        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return self::summaries($query->orderByDesc('created_at')->limit($limit)->get());
    }

    /**
     * Posts matching `$filters` (type, departmentId, search, sort), newest (or oldest) first, one page at a time.
     *
     * @return array{items: list<array>, total: int, page: int, perPage: int, pageCount: int}
     */
    public static function list(array $filters, int $perPage, int $page = 1): array
    {
        $query = self::listed();
        if (isset($filters['type']) && $filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['departmentId'])) {
            $query->where('department_id', $filters['departmentId']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = Search::textContains($search);
            $query->where(fn (Builder $q) => $q
                ->whereRaw("title like ? escape '!'", [$like])
                ->orWhereRaw("search_text like ? escape '!'", [$like]));
        }

        $total = (clone $query)->count();
        $rows = $query->select(self::COLUMNS)->with('department:id,name,slug')
            ->orderBy('created_at', ($filters['sort'] ?? null) === 'oldest' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return [
            'items' => self::summaries($rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * One page of the public `/posts` list; `null` when `$page` is past the end.
     *
     * @return array{result: array, layout: array}|null
     */
    public static function listingPage(array $filters, int $page, int $perPage): ?array
    {
        $layout = SiteLayout::read();
        $result = self::list($filters, $perPage, $page);
        if ($page > $result['pageCount']) {
            return null;
        }

        return ['result' => $result, 'layout' => $layout];
    }

    /** @return array{post: array, related: list<array>, layout: array}|null */
    public static function page(string $slug): ?array
    {
        $row = Post::query()->select([...self::COLUMNS, 'content', 'hide_author', 'is_hidden', 'user_id'])
            ->with(['department:id,name,slug', 'user:id,name'])->where('slug', $slug)->first();
        if (! $row) {
            return null;
        }
        $layout = SiteLayout::read();
        $post = [
            ...self::summaryWithExcerpt($row, self::excerptFor($row, $row->content)),
            'html' => Content::parseContent($row->content)->html(),
            'authorName' => $row->hide_author ? null : $row->user->name,
            'isHidden' => (bool) $row->is_hidden,
        ];

        return ['post' => $post, 'related' => self::related($post), 'layout' => $layout];
    }

    /**
     * Summaries for post cards and lists. The Excerpt is the clamped `search_text` (the plain text of the content, kept
     * by every writer), so the content is not parsed; only rows whose `search_text` is empty (never backfilled, or no
     * text at all) load their content and take `excerptOf(content)`.
     *
     * @param  iterable<Post>  $rows
     * @return list<array>
     */
    public static function summaries(iterable $rows): array
    {
        $rows = collect($rows);
        $blankIds = $rows->filter(fn (Post $row) => $row->search_text === null || $row->search_text === '')->pluck('id')->all();
        $contents = $blankIds === [] ? [] : Post::query()->whereIn('id', $blankIds)->pluck('content', 'id')->all();

        return $rows->map(fn (Post $row) => self::summaryWithExcerpt(
            $row,
            self::excerptFor($row, $contents[$row->id] ?? null),
        ))->values()->all();
    }

    /**
     * The one Excerpt rule: the clamped stored `search_text`; only a row without it (never backfilled, or no text at
     * all) falls back to `excerptOf($content)`.
     */
    private static function excerptFor(Post $row, ?string $content): string
    {
        return ($row->search_text !== null && $row->search_text !== '')
            ? Content::clampExcerpt($row->search_text)
            : Content::excerptOf($content);
    }

    /**
     * Up to `$limit` Posts: the newest of the same Department first, then the newest of the same Post type. The type
     * query takes `2 × limit` so that dropping the Department's Posts from it still leaves enough.
     *
     * @return list<array>
     */
    public static function related(array $post, int $limit = 3): array
    {
        $sameDepartment = $post['department']
            ? self::query()->where('department_id', $post['department']['id'])->where('id', '!=', $post['id'])
                ->orderByDesc('created_at')->limit($limit)->get()
            : collect();
        $sameType = self::query()->where('type', $post['type'])->where('id', '!=', $post['id'])
            ->orderByDesc('created_at')->limit($post['department'] ? 2 * $limit : $limit)->get();

        $taken = $sameDepartment->pluck('id')->flip();
        $rows = $sameDepartment->concat($sameType->reject(fn (Post $row) => $taken->has($row->id)))->take($limit);

        return self::summaries($rows);
    }

    /** The Posts that public lists may show: every Post except a Hidden post. */
    public static function listed(): Builder
    {
        return Post::query()->where('is_hidden', false);
    }

    private static function query(): Builder
    {
        return self::listed()->select(self::COLUMNS)->with('department:id,name,slug');
    }

    private static function summaryWithExcerpt(Post $row, string $excerpt): array
    {
        return [
            'id' => $row->id,
            'title' => $row->title,
            'slug' => $row->slug,
            'type' => $row->type,
            'excerpt' => $excerpt,
            'imageUrl' => Storage::publicUrl($row->highlight_image_path),
            'imageSmallUrl' => Storage::variantUrl($row->highlight_image_path, Variants::CARD_WIDTH),
            'startDate' => $row->start_date,
            'endDate' => $row->end_date,
            'isFeatured' => (bool) $row->is_featured,
            'createdAt' => $row->created_at,
            'updatedAt' => $row->updated_at,
            'department' => $row->department ? ['id' => $row->department->id, 'name' => $row->department->name, 'slug' => $row->department->slug] : null,
        ];
    }
}
