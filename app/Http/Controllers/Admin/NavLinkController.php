<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminPage;
use App\Admin\NavTree;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Post;
use App\Site\SiteNav;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class NavLinkController extends Controller
{
    public function __invoke(Request $request): View
    {
        AdminPage::resolve($request, ['superAdmin' => true]);

        $links = NavTree::stored();
        $linkedSlugs = NavTree::postSlugsLinked(NavTree::urls($links));
        $departments = Department::query()->orderBy('name')->get(['name', 'slug'])
            ->map(fn (Department $row): array => ['name' => $row->name, 'slug' => $row->slug])->all();
        $newestPosts = Post::query()->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get(['title', 'slug']);
        $linkedPosts = $linkedSlugs === [] ? collect() : Post::query()->whereIn('slug', $linkedSlugs)->get(['slug']);

        // Suggestions for the link picker: pages, the auto departments menu, every department, the 30 newest posts.
        $suggestions = [
            ...NavTree::PAGE_SUGGESTIONS,
            ['group' => 'Pages', 'label' => 'Departments menu (auto)', 'url' => SiteNav::DEPARTMENTS_MENU_URL],
            ...array_map(fn (array $d): array => ['group' => 'Departments', 'label' => $d['name'], 'url' => SiteNav::departmentHref($d['slug'])], $departments),
            ...$newestPosts->map(fn (Post $p): array => ['group' => 'Posts', 'label' => $p->title, 'url' => SiteNav::postHref($p->slug)])->all(),
        ];
        // Paths the editor may treat as existing. Links only change through actions that reload this page, so the Posts
        // to check are exactly those the stored links point at; no other slug is sent to the client.
        $knownPaths = [
            ...SiteNav::STATIC_PATHS,
            ...array_map(fn (array $d): string => SiteNav::departmentHref($d['slug']), $departments),
            ...$linkedPosts->map(fn (Post $p): string => SiteNav::postHref($p->slug))->all(),
        ];

        return view('admin.navlinks.index', [
            'links' => $links,
            'departments' => $departments,
            'suggestions' => $suggestions,
            'knownPaths' => $knownPaths,
        ]);
    }
}
