<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Post;
use App\Site\PostFilters;
use App\Storage\Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(Request $request)
    {
        ['actor' => $actor] = AdminPage::resolve($request);
        $scope = AdminScope::current($request);

        // Each filter is declared once: its query parameter, how it parses, how a value narrows the query.
        $filters = [
            'type' => AdminList::listFilter(
                fn (?string $raw) => in_array($raw, PostFilters::POST_TYPES, true) ? $raw : null,
                fn (string $type) => fn (Builder $q) => $q->where('type', $type),
            ),
            'featured' => AdminList::listFilter(
                fn (?string $raw) => AdminList::parseYesNo($raw),
                fn (bool $isFeatured) => fn (Builder $q) => $q->where('is_featured', $isFeatured),
            ),
        ];

        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => $scope,
            'spec' => [
                'fields' => [
                    'title' => AdminList::sortColumn('title'),
                    'type' => AdminList::sortColumn('type'),
                    'department' => AdminList::sortBy(fn (string $dir) => fn (Builder $q) => $q->orderBy(
                        Department::query()->select('name')->whereColumn('departments.id', 'posts.department_id'),
                        $dir,
                    )),
                    'featured' => AdminList::sortColumn('is_featured'),
                    'created' => AdminList::sortColumn('created_at', 'desc'),
                ],
                'defaultSort' => 'created',
                'tiebreak' => AdminList::orderColumn('id', 'desc'),
                'search' => ['title', 'search_text'],
            ],
            'filters' => $filters,
            'count' => fn ($where) => Post::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(
                Post::query()
                    ->select(['id', 'title', 'type', 'is_featured', 'created_at', 'highlight_image_path', 'department_id', 'user_id'])
                    ->with(['department:id,name', 'user:id,name']),
                $args,
            )->get(),
        ]);

        return view('admin.posts.index', [
            'list' => $list,
            'posts' => $list->rows,
            'postTypes' => PostFilters::POST_TYPES,
            // A single department or main page: the Department column adds nothing.
            'showDepartment' => $scope['kind'] === 'all',
            'imageUrls' => $list->rows->mapWithKeys(fn (Post $p) => [$p->id => Storage::publicUrl($p->highlight_image_path)])->all(),
        ]);
    }

    public function create(Request $request)
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        return view('admin.posts.create', [
            'departments' => DepartmentAccess::departmentOptions($actor),
            'isSuperAdmin' => $actor->isSuperAdmin(),
            'defaultDepartmentId' => AdminScope::scopeDefaultDepartment(AdminScope::current($request)),
        ]);
    }

    public function edit(Request $request, string $id)
    {
        ['actor' => $actor, 'row' => $post] = AdminPage::resolve($request, [
            'load' => fn ($actor) => DepartmentAccess::loadScoped($actor, 'post', AdminPage::parseId($id)),
        ]);

        return view('admin.posts.edit', [
            'post' => $post,
            'departments' => DepartmentAccess::departmentOptions($actor),
            'isSuperAdmin' => $actor->isSuperAdmin(),
            'values' => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'content' => $post->content,
                'type' => $post->type,
                'startDate' => $post->start_date?->toISOString(),
                'endDate' => $post->end_date?->toISOString(),
                'departmentId' => $post->department_id,
                'isFeatured' => $post->is_featured,
                'hideAuthor' => $post->hide_author,
                'highlightImageUrl' => Storage::publicUrl($post->highlight_image_path),
            ],
        ]);
    }
}
