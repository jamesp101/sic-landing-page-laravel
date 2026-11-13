<?php

namespace Tests\Unit\Admin\Core;

use App\Admin\AdminList;
use App\Admin\ListState;
use App\Models\Department;
use App\Models\Post;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminListTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['kind' => 'all'];

    private const CBA = ['kind' => 'department', 'id' => 11, 'name' => 'CBA', 'slug' => 'cba'];

    private User $admin;

    private User $cit;

    protected function setUp(): void
    {
        parent::setUp();
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'CBA', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();

        // CIT: "Alpha 1".."Alpha 6"; CBA: "Alpha 7", "Alpha 8", "Beta 9"; general: "Alpha 10".
        $rows = [
            ...array_map(fn ($n) => ["Alpha {$n}", 10], range(1, 6)),
            ...array_map(fn ($n) => ["Alpha {$n}", 11], [7, 8]),
            ['Beta 9', 11],
            ['Alpha 10', null],
        ];
        foreach ($rows as $index => [$title, $departmentId]) {
            Post::query()->forceCreate([
                'title' => $title, 'slug' => "p-{$index}", 'content' => '', 'search_text' => '', 'type' => 'article',
                'department_id' => $departmentId, 'user_id' => $this->admin->id,
                'created_at' => now()->startOfYear()->addDays($index),
            ]);
        }
    }

    private function filters(): array
    {
        return [
            'type' => AdminList::listFilter(
                fn (?string $raw) => in_array($raw, ['article', 'event'], true) ? $raw : null,
                fn (string $type) => fn (Builder $q) => $q->where('type', $type),
            ),
            'featured' => AdminList::listFilter(
                fn (?string $raw) => AdminList::parseYesNo($raw),
                fn (bool $featured) => fn (Builder $q) => $q->where('is_featured', $featured),
            ),
        ];
    }

    private function spec(): array
    {
        return [
            'fields' => [
                'title' => AdminList::sortColumn('title'),
                'created' => AdminList::sortColumn('created_at', 'desc'),
            ],
            'defaultSort' => 'created',
            'tiebreak' => AdminList::orderColumn('id', 'desc'),
            'search' => ['title', 'search_text'],
            'perPage' => 4,
        ];
    }

    private function list(User $actor, string $query = '', array $scope = self::ALL, array $extra = []): ListState
    {
        return AdminList::runList($extra + [
            'url' => "/admin/posts{$query}",
            'actor' => $actor,
            'scope' => $scope,
            'spec' => $this->spec(),
            'count' => fn (Closure $where) => Post::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(Post::query(), $args)->get(),
        ]);
    }

    private function titles(ListState $state): array
    {
        return collect($state->rows)->pluck('title')->all();
    }

    public function test_a_department_user_stays_in_their_department_through_search_sort_and_page(): void
    {
        foreach (['', '?q=Alpha', '?q=Beta', '?sort=title&dir=desc', '?page=2', '?q=Alpha&sort=title&page=2'] as $query) {
            $state = $this->list($this->cit, $query, self::CBA);
            $this->assertSame(str_contains($query, 'Beta') ? 0 : 6, $state->total, $query);
            $seen = [];
            for ($page = 1; $page <= $state->pageCount; $page++) {
                $paged = $this->list($this->cit, $query.($query ? '&' : '?')."page={$page}");
                array_push($seen, ...$this->titles($paged));
            }
            foreach ($seen as $title) {
                $this->assertLessThanOrEqual(6, (int) explode(' ', $title)[1], "{$query} {$title}");
            }
        }
    }

    public function test_a_superadmin_narrows_by_scope_and_search_together(): void
    {
        $this->assertSame(['Alpha 10'], $this->titles($this->list($this->admin, '?q=Alpha', ['kind' => 'main'])));
        $this->assertSame(2, $this->list($this->admin, '?q=Alpha', self::CBA)->total);
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->assertSame(0, $this->list($this->admin, '?q=%25')->total);
        $this->assertSame(0, $this->list($this->admin, '?q=Alpha_1')->total);
    }

    public function test_sorts_only_by_allow_listed_fields(): void
    {
        $this->assertSame('title', $this->list($this->admin, '?sort=title&dir=asc')->sort);
        foreach (['user_id', 'constructor', '__proto__', 'id', ''] as $bad) {
            $state = $this->list($this->admin, "?sort={$bad}");
            $this->assertSame('created', $state->sort);
            $this->assertSame('desc', $state->dir);
            $this->assertSame('Alpha 10', $this->titles($state)[0]);
        }
        $this->assertSame('asc', $this->list($this->admin, '?sort=title&dir=sideways')->dir);
    }

    public function test_out_of_range_page_shows_the_last_page_and_pages_do_not_overlap(): void
    {
        $first = $this->list($this->admin);
        $this->assertSame([10, 3, 1], [$first->total, $first->pageCount, $first->page]);
        $last = $this->list($this->admin, '?page=99');
        $this->assertSame(3, $last->page);
        $this->assertCount(2, $last->rows);
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            foreach ($this->list($this->admin, "?page={$page}")->rows as $row) {
                $seen[$row->id] = true;
            }
        }
        $this->assertCount(10, $seen);
        $empty = $this->list($this->admin, '?q=nothing-matches&page=5');
        $this->assertSame([0, 1, 1], [$empty->total, $empty->pageCount, $empty->page]);
        $this->assertCount(0, $empty->rows);
    }

    public function test_sort_and_page_links_keep_the_other_parameters(): void
    {
        $state = $this->list($this->admin, '?q=Alpha&page=2');
        $this->assertSame('/admin/posts?q=Alpha&sort=title&dir=asc', $state->sortHref('title'));
        $this->assertSame('/admin/posts?q=Alpha&sort=created&dir=asc', $state->sortHref('created'));
        $this->assertSame('descending', $state->ariaSort('created'));
        $this->assertNull($state->ariaSort('title'));
        $this->assertSame('/admin/posts?q=Alpha', $state->pageHref(1));
        $this->assertSame('/admin/posts?q=Alpha&page=3', $state->pageHref(3));
        $this->assertSame('/admin/posts?sort=title&dir=desc', $this->list($this->admin, '?sort=title&dir=asc')->sortHref('title'));
    }

    public function test_a_declared_filter_narrows_and_an_invalid_value_is_ignored(): void
    {
        $filters = ['filters' => $this->filters()];
        $type = $this->list($this->admin, '?type=article', self::ALL, $filters);
        $this->assertTrue($type->filtered);
        $this->assertSame(['type' => 'article', 'featured' => null], $type->values);
        $this->assertSame(10, $type->total);

        Post::query()->whereKey(1)->update(['is_featured' => true]);
        $featured = $this->list($this->admin, '?featured=yes', self::ALL, $filters);
        $this->assertTrue($featured->values['featured']);
        $this->assertSame(1, $featured->total);
        $notFeatured = $this->list($this->admin, '?featured=no', self::ALL, $filters);
        $this->assertFalse($notFeatured->values['featured']);
        $this->assertSame(9, $notFeatured->total);

        $invalid = $this->list($this->admin, '?type=bogus&featured=maybe', self::ALL, $filters);
        $this->assertFalse($invalid->filtered);
        $this->assertSame(['type' => null, 'featured' => null], $invalid->values);
        $this->assertSame(10, $invalid->total);
    }

    public function test_filtered_counts_search_and_filters_only(): void
    {
        $filters = ['filters' => $this->filters()];
        $run = fn (string $query) => $this->list($this->admin, $query, self::ALL, $filters)->filtered;
        $this->assertFalse($run(''));
        $this->assertFalse($run('?sort=title&dir=asc&page=2'));
        $this->assertTrue($run('?q=Alpha'));
        $this->assertFalse($run('?q=%20%20'));
    }

    public function test_reset_drops_search_page_and_filters_but_keeps_sort(): void
    {
        $state = $this->list($this->admin, '?q=Alpha&type=article&featured=yes&page=2&sort=title&dir=desc&other=1', self::ALL, ['filters' => $this->filters()]);
        $this->assertSame('/admin/posts?sort=title&dir=desc&other=1', $state->resetHref());
        $this->assertSame('/admin/posts', $this->list($this->admin, '?q=Alpha&page=2')->resetHref());
    }

    public function test_the_actor_restriction_holds_with_a_filter(): void
    {
        $filters = ['filters' => $this->filters()];
        $this->assertSame(6, $this->list($this->cit, '?type=article', self::CBA, $filters)->total);
        $this->assertSame(6, $this->list($this->cit, '?featured=no', self::CBA, $filters)->total);
    }

    public function test_a_list_with_its_own_restriction_reports_filtered_too(): void
    {
        $restricted = fn (string $query) => $this->list($this->admin, $query, self::ALL, ['restriction' => fn (Builder $q) => null]);
        $this->assertFalse($restricted('')->filtered);
        $this->assertTrue($restricted('?q=Beta')->filtered);
        $this->assertSame(1, $restricted('?q=Beta')->total);
    }

    public function test_sort_options_list_labelled_fields_with_toggling_links_and_the_active_one(): void
    {
        $extra = ['spec' => ['fields' => [
            'title' => AdminList::sortColumn('title', 'asc', 'Title'),
            'created' => AdminList::sortColumn('created_at', 'desc', 'Date'),
            'hidden' => AdminList::sortColumn('id'),
        ]] + $this->spec()];

        $options = $this->list($this->admin, '?q=Alpha&page=2&sort=title&dir=asc', self::ALL, $extra)->sortOptions();
        $this->assertSame([
            ['label' => 'Title', 'field' => 'title', 'href' => '/admin/posts?q=Alpha&sort=title&dir=desc', 'active' => true],
            ['label' => 'Date', 'field' => 'created', 'href' => '/admin/posts?q=Alpha&sort=created&dir=desc', 'active' => false],
        ], $options);
    }

    public function test_clear_filter_href_drops_only_that_filter(): void
    {
        $state = $this->list($this->admin, '?q=Alpha&type=article&featured=yes&page=2', self::ALL, ['filters' => $this->filters()]);
        $this->assertSame('/admin/posts?q=Alpha&featured=yes', $state->clearFilterHref('type'));
    }

    public function test_the_department_column_shows_only_when_the_scope_spans_all_departments(): void
    {
        $this->assertTrue($this->list($this->admin, '', self::ALL)->showDepartment);
        $this->assertFalse($this->list($this->admin, '', self::CBA)->showDepartment);
        $this->assertFalse($this->list($this->admin, '', ['kind' => 'main'])->showDepartment);
        $this->assertFalse($this->list($this->cit, '', self::CBA)->showDepartment);
    }

    public function test_an_unrestricted_list_ignores_the_scope_but_search_still_narrows(): void
    {
        $unrestricted = fn (string $query) => $this->list($this->admin, $query, self::CBA, ['restriction' => AdminList::unrestricted()]);
        $this->assertSame(10, $unrestricted('')->total);
        $this->assertSame(1, $unrestricted('?q=Beta')->total);
    }
}
