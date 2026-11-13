<?php

namespace App\Site;

use App\Carousel\Slides;
use App\Content\Content;
use App\Content\WelcomeHero;
use App\Models\Department;
use App\Models\HeroSection;
use App\Models\Member;
use App\Models\NavigationLink;
use App\Models\Post;
use App\OrgChart\OrgTree;
use App\Storage\Storage;
use App\Storage\Variants;
use App\Support\Search;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public site's reads. Results are view models: arrays with the camelCase keys of `resources/js/lib/content-types.ts`
 * (URLs already resolved through Storage, dates as Carbon). Every page query returns `layout` (see `layout()`), computed
 * after the page's own row lookup so an unknown slug costs one query.
 */
final class Queries
{
    private const DEPARTMENT_PAGE_POSTS = 6;

    private const POST_COLUMNS = [
        'id', 'title', 'slug', 'type', 'search_text', 'highlight_image_path', 'start_date', 'end_date',
        'is_featured', 'created_at', 'updated_at', 'department_id',
    ];

    private const DEPARTMENT_COLUMNS = ['id', 'name', 'slug', 'description', 'logo_path', 'updated_at'];

    /**
     * The layout's one input (`<x-layouts.public :layout>`): ['navLinks' => list<nav link view>, 'departments' => list<department summary>].
     *
     * @return array{navLinks: array, departments: array}
     */
    public static function layout(): array
    {
        return ['navLinks' => self::getNavLinks(), 'departments' => self::getDepartments()];
    }

    /**
     * Active Navigation links with their active children: one flat query, nested in memory.
     *
     * @return list<array{id: int, label: string, url: string, children: list<array>}>
     */
    public static function getNavLinks(): array
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

    /** @return list<array> */
    public static function getDepartments(): array
    {
        return Department::query()->orderBy('name')->get(self::DEPARTMENT_COLUMNS)
            ->map(fn (Department $row) => self::toDepartmentSummary($row))->all();
    }

    /** @return array{carousel: array, latestPosts: array, welcomeHero: ?string, heroSections: array, layout: array} */
    public static function getHomeData(): array
    {
        $layout = self::layout();
        $latest = self::postQuery()->orderByDesc('created_at')->limit(6)->get();

        return [
            'carousel' => Slides::findSlides(['showOnMain' => true]),
            'latestPosts' => self::toPostSummaries($latest),
            'welcomeHero' => WelcomeHero::getWelcomeHero(),
            'heroSections' => self::heroSections(null),
            'layout' => $layout,
        ];
    }

    /**
     * A Department's page: its Slides, People, hero sections and its 6 newest Posts, with the total for "View all".
     *
     * @return array{department: array, carousel: array, posts: array, postCount: int, members: array, heroSections: array, seo: array, layout: array}|null
     */
    public static function getDepartmentPage(string $slug, string $site): ?array
    {
        $row = Department::query()->where('slug', $slug)->first([...self::DEPARTMENT_COLUMNS, 'hero_content']);
        if (! $row) {
            return null;
        }
        $layout = self::layout();

        $carousel = Slides::findSlides(['departmentId' => $row->id]);
        $posts = self::postQuery()->where('department_id', $row->id)->orderByDesc('created_at')->limit(self::DEPARTMENT_PAGE_POSTS)->get();
        $postCount = Post::query()->where('department_id', $row->id)->count();
        $memberRows = Member::query()->where('department_id', $row->id)
            ->get(['id', 'name', 'role', 'image_path', 'parent_id', 'order'])
            ->map(fn (Member $m) => [
                'id' => $m->id, 'name' => $m->name, 'role' => $m->role, 'imagePath' => $m->image_path,
                'parentId' => $m->parent_id, 'order' => $m->order,
            ])->all();

        $toNode = function (array $node) use (&$toNode): array {
            $member = $node['member'];

            return [
                'id' => $member['id'],
                'name' => $member['name'],
                'role' => $member['role'],
                'imageUrl' => Storage::publicUrl($member['imagePath']),
                'imageSmallUrl' => Storage::variantUrl($member['imagePath'], Variants::THUMB_WIDTH),
                'children' => array_map($toNode, $node['children']),
            ];
        };
        $members = array_map($toNode, OrgTree::buildOrgTree($memberRows));
        $department = [...self::toDepartmentSummary($row), 'heroContent' => $row->hero_content];

        return [
            'department' => $department,
            'carousel' => $carousel,
            'posts' => self::toPostSummaries($posts),
            'postCount' => $postCount,
            'members' => $members,
            'heroSections' => self::heroSections($row->id),
            'seo' => Seo::departmentPageSeo($department, $members, $carousel[0]['imageUrl'] ?? null, $site),
            'layout' => $layout,
        ];
    }

    /**
     * Posts matching `$filters` (type, departmentId, search, sort), newest (or oldest) first, one page at a time.
     *
     * @return array{items: list<array>, total: int, page: int, perPage: int, pageCount: int}
     */
    public static function listPosts(array $filters, int $perPage, int $page = 1): array
    {
        $query = Post::query();
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
        $rows = $query->select(self::POST_COLUMNS)->with('department:id,name,slug')
            ->orderBy('created_at', ($filters['sort'] ?? null) === 'oldest' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return [
            'items' => self::toPostSummaries($rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * One page of the public `/posts` list; `null` when `$page` is past the end.
     *
     * @return array{result: array, seo: array, layout: array}|null
     */
    public static function getPostsPage(array $filters, int $page, int $perPage, string $site): ?array
    {
        $layout = self::layout();
        $result = self::listPosts($filters, $perPage, $page);
        if ($page > $result['pageCount']) {
            return null;
        }

        return ['result' => $result, 'seo' => Seo::postsListSeo($result['items'], $filters, $page, $site), 'layout' => $layout];
    }

    /** @return array{post: array, related: list<array>, seo: array, layout: array}|null */
    public static function getPostPage(string $slug, string $site): ?array
    {
        $row = Post::query()->select([...self::POST_COLUMNS, 'content', 'hide_author', 'user_id'])
            ->with(['department:id,name,slug', 'user:id,name'])->where('slug', $slug)->first();
        if (! $row) {
            return null;
        }
        $layout = self::layout();
        $content = Content::parseContent($row->content);
        $post = [
            ...self::summaryWithExcerpt($row, self::excerptFor($row, $row->content)),
            'html' => $content->html(),
            'authorName' => $row->hide_author ? null : $row->user->name,
        ];

        return ['post' => $post, 'related' => self::getRelatedPosts($post), 'seo' => Seo::postPageSeo($post, $site), 'layout' => $layout];
    }

    /**
     * @return array{departments: list<array{slug: string, updatedAt: Carbon}>, posts: list<array{slug: string, updatedAt: Carbon, imageUrl: ?string, title: string}>}
     */
    public static function getSitemapData(): array
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

    /**
     * Summaries for post cards and lists. The Excerpt is the clamped `search_text` (the plain text of the content, kept
     * by every writer), so the content is not parsed; only rows whose `search_text` is empty (never backfilled, or no
     * text at all) load their content and take `excerptOf(content)`.
     *
     * @param  iterable<Post>  $rows
     * @return list<array>
     */
    public static function toPostSummaries(iterable $rows): array
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
    private static function getRelatedPosts(array $post, int $limit = 3): array
    {
        $sameDepartment = $post['department']
            ? self::postQuery()->where('department_id', $post['department']['id'])->where('id', '!=', $post['id'])
                ->orderByDesc('created_at')->limit($limit)->get()
            : collect();
        $sameType = self::postQuery()->where('type', $post['type'])->where('id', '!=', $post['id'])
            ->orderByDesc('created_at')->limit($post['department'] ? 2 * $limit : $limit)->get();

        $taken = $sameDepartment->pluck('id')->flip();
        $rows = $sameDepartment->concat($sameType->reject(fn (Post $row) => $taken->has($row->id)))->take($limit);

        return self::toPostSummaries($rows);
    }

    private static function postQuery(): Builder
    {
        return Post::query()->select(self::POST_COLUMNS)->with('department:id,name,slug');
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

    private static function toDepartmentSummary(Department $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'description' => $row->description,
            'logoUrl' => Storage::publicUrl($row->logo_path),
            'logoSmallUrl' => Storage::variantUrl($row->logo_path, Variants::THUMB_WIDTH),
            'updatedAt' => $row->updated_at,
        ];
    }

    /** @return list<array{id: int, title: ?string, content: string}> */
    private static function heroSections(?int $departmentId): array
    {
        return HeroSection::query()
            ->where('department_id', $departmentId)->where('is_active', true)
            ->orderBy('order')->orderBy('id')
            ->get(['id', 'title', 'content'])
            ->map(fn (HeroSection $h) => ['id' => $h->id, 'title' => $h->title, 'content' => $h->content])->all();
    }
}
