<?php

namespace Tests\Feature\Admin\Trees;

use App\Models\NavigationLink;
use App\Models\User;
use App\Support\Flash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Authorization, input rules, messages and results of `navlinks.*`. */
class NavlinksActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->create();
    }

    private function action(?User $user, string $name, array $input = []): TestResponse
    {
        return ($user ? $this->actingAs($user) : $this)->postJson("/_actions/navlinks.{$name}", $input);
    }

    private function link(string $label, ?int $parentId = null, int $order = 0): NavigationLink
    {
        return NavigationLink::query()->forceCreate(['label' => $label, 'url' => "/{$label}", 'order' => $order, 'is_active' => true, 'parent_id' => $parentId]);
    }

    private function form(array $over = []): array
    {
        return ['label' => 'News', 'url' => '/posts', 'order' => 1, 'isActive' => true, ...$over];
    }

    public function test_signed_out_calls_are_unauthorized(): void
    {
        foreach (['create', 'update', 'reorder', 'delete'] as $name) {
            $this->action(null, $name)->assertStatus(401);
        }
    }

    public function test_only_superadmins_may_change_navigation_links(): void
    {
        $link = $this->link('top');
        $this->action($this->cit, 'create', $this->form())->assertStatus(403);
        $this->action($this->cit, 'update', $this->form(['id' => $link->id, 'label' => 'Hijack']))->assertStatus(403);
        $this->action($this->cit, 'reorder', ['items' => [['id' => $link->id, 'parentId' => null, 'order' => 5]]])->assertStatus(403);
        $this->action($this->cit, 'delete', ['id' => $link->id])->assertStatus(403);
        $this->assertSame(['top', 0], [$link->fresh()->label, $link->fresh()->order]);
        $this->assertSame(1, NavigationLink::query()->count());
    }

    public function test_create_stores_the_link_and_flashes(): void
    {
        $top = $this->link('top');
        $this->action($this->admin, 'create', $this->form(['label' => '  News  ', 'parentId' => $top->id]))->assertOk()
            ->assertCookie(Flash::COOKIE, Flash::encode('success', 'Navigation link created.'));
        $link = NavigationLink::query()->where('label', 'News')->first();
        $this->assertSame(['/posts', 1, true, $top->id], [$link->url, $link->order, $link->is_active, $link->parent_id]);
    }

    public function test_input_messages_match_the_ts_schema(): void
    {
        $messages = fn (array $over, string $field) => $this->action($this->admin, 'create', $this->form($over))->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError')->json("error.fields.{$field}.0");
        $this->assertSame('The label is required.', $messages(['label' => '  '], 'label'));
        $this->assertSame('The URL is required.', $messages(['url' => ''], 'url'));
        $this->assertSame('Use a path such as /posts or a full https:// URL.', $messages(['url' => 'javascript:alert(1)'], 'url'));
        $this->assertSame('Use a path such as /posts or a full https:// URL.', $messages(['url' => '//evil.test'], 'url'));
        $this->assertSame('The order must be a number.', $messages(['order' => 'first'], 'order'));
        $this->assertSame('Too big: expected string to have <=255 characters', $messages(['label' => str_repeat('x', 256)], 'label'));
        $this->assertSame(0, NavigationLink::query()->count());
    }

    public function test_create_rejects_a_bad_parent_with_the_rule_message(): void
    {
        $top = $this->link('top');
        $child = $this->link('child', $top->id);
        $this->action($this->admin, 'create', $this->form(['parentId' => 9999]))->assertStatus(400)->assertJsonPath('error.message', 'The selected parent link does not exist.');
        $this->action($this->admin, 'create', $this->form(['parentId' => $child->id]))->assertStatus(400)->assertJsonPath('error.message', 'Only top-level links can be used as a parent.');
    }

    public function test_update_changes_the_link_and_guards_the_two_level_rule(): void
    {
        $top = $this->link('top');
        $this->link('child', $top->id);
        $other = $this->link('other');

        $this->action($this->admin, 'update', $this->form(['id' => $other->id, 'label' => 'Renamed', 'isActive' => false]))->assertOk()->assertJsonPath('data.id', $other->id)
            ->assertCookie(Flash::COOKIE, Flash::encode('success', 'Navigation link updated.'));
        $this->assertSame(['Renamed', false], [$other->fresh()->label, $other->fresh()->is_active]);

        $this->action($this->admin, 'update', $this->form(['id' => $top->id, 'parentId' => $other->id]))->assertStatus(400)
            ->assertJsonPath('error.message', 'A link that has sub-links cannot become a sub-link itself.');
        $this->action($this->admin, 'update', $this->form(['id' => $top->id, 'parentId' => $top->id]))->assertStatus(400)
            ->assertJsonPath('error.message', 'A link cannot be its own parent.');
        $this->action($this->admin, 'update', $this->form(['id' => 9999]))->assertStatus(404);
    }

    public function test_reorder_applies_the_whole_tree_in_one_transaction(): void
    {
        $a = $this->link('a', null, 1);
        $b = $this->link('b', null, 2);
        $c = $this->link('c', $b->id, 1);

        $this->action($this->admin, 'reorder', ['items' => [
            ['id' => $b->id, 'parentId' => null, 'order' => 1], ['id' => $a->id, 'parentId' => $b->id, 'order' => 1], ['id' => $c->id, 'parentId' => $b->id, 'order' => 2],
        ]])->assertOk()->assertJsonPath('data.count', 3);
        $this->assertSame([[1, null], [1, $b->id], [2, $b->id]], [[$b->fresh()->order, $b->fresh()->parent_id], [$a->fresh()->order, $a->fresh()->parent_id], [$c->fresh()->order, $c->fresh()->parent_id]]);
    }

    public function test_reorder_rejects_an_invalid_tree_and_changes_nothing(): void
    {
        $a = $this->link('a', null, 1);
        $b = $this->link('b', null, 2);
        $c = $this->link('c', $b->id, 1);
        $before = NavigationLink::query()->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray();

        $bad = [
            [[['id' => $a->id, 'parentId' => null, 'order' => 5], ['id' => $b->id, 'parentId' => $a->id, 'order' => 1]], 'Navigation is two levels deep: only top-level links can have sub-links.'],
            [[['id' => $a->id, 'parentId' => null, 'order' => 5], ['id' => $a->id, 'parentId' => null, 'order' => 6]], 'A link appears more than once.'],
            [[['id' => 9999, 'parentId' => null, 'order' => 1]], 'One of the links no longer exists.'],
            [[['id' => $a->id, 'parentId' => $a->id, 'order' => 1]], 'A link cannot be its own parent.'],
            [[['id' => $a->id, 'parentId' => 9999, 'order' => 1]], 'The selected parent link does not exist.'],
        ];
        foreach ($bad as [$items, $message]) {
            $this->action($this->admin, 'reorder', ['items' => $items])->assertStatus(400)->assertJsonPath('error.message', $message);
        }
        $this->assertSame($before, NavigationLink::query()->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray());
        $this->assertSame($c->id, NavigationLink::query()->where('parent_id', $b->id)->value('id'));
    }

    public function test_reorder_validates_the_payload_shape(): void
    {
        $this->action($this->admin, 'reorder', ['items' => []])->assertStatus(400)->assertJsonPath('error.fields.items.0', 'Too small: expected array to have >=1 items');
        $this->action($this->admin, 'reorder', ['items' => [['id' => 1, 'parentId' => null, 'order' => -1]]])->assertStatus(400)
            ->assertJsonPath('error.fields', ['items.0.order' => ['Too small: expected number to be >=0']]);
    }

    public function test_delete_removes_the_link_and_flashes(): void
    {
        $link = $this->link('gone');
        $this->action($this->admin, 'delete', ['id' => $link->id])->assertOk()->assertJsonPath('data.id', $link->id)
            ->assertCookie(Flash::COOKIE, Flash::encode('success', 'Navigation link deleted.'));
        $this->assertNull(NavigationLink::query()->find($link->id));
        $this->action($this->admin, 'delete', ['id' => $link->id])->assertStatus(404);
    }

    public function test_reorder_rejects_numeric_strings(): void
    {
        $a = $this->link('a');
        foreach ([['id' => (string) $a->id, 'parentId' => null, 'order' => 1], ['id' => $a->id, 'parentId' => '1', 'order' => 1], ['id' => $a->id, 'parentId' => null, 'order' => '1']] as $item) {
            $this->action($this->admin, 'reorder', ['items' => [$item]])->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError');
        }
    }
}
