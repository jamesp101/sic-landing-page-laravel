<?php

namespace Tests\Feature\Admin\Trees;

use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Carousel\StoresFiles;

/** Authorization, input rules, messages and results of `members.*`. */
class MembersActionsTest extends TestCase
{
    use RefreshDatabase;
    use StoresFiles;

    private User $admin;

    private User $cit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'CBA', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();
    }

    private function action(?User $user, string $name, array $input = []): TestResponse
    {
        return ($user ? $this->actingAs($user) : $this)->postJson("/_actions/members.{$name}", $input);
    }

    private function form(User $user, string $name, array $input): TestResponse
    {
        return $this->actingAs($user)->post("/_actions/members.{$name}", $input, ['Accept' => 'application/json']);
    }

    private function member(int $department, string $name, ?int $parentId = null, int $order = 1, ?string $image = null): Member
    {
        return Member::query()->forceCreate(['department_id' => $department, 'name' => $name, 'role' => 'Role', 'parent_id' => $parentId, 'order' => $order, 'image_path' => $image])->fresh();
    }

    private function fields(array $over = []): array
    {
        return ['departmentId' => 10, 'name' => 'Ada', 'role' => 'Dean', 'order' => 1, ...$over];
    }

    public function test_signed_out_calls_are_unauthorized(): void
    {
        foreach (['create', 'update', 'reorder', 'delete'] as $name) {
            $this->action(null, $name)->assertStatus(401);
        }
    }

    public function test_a_department_user_cannot_manage_another_departments_org_chart(): void
    {
        $cba = $this->member(11, 'Boss');
        $this->form($this->cit, 'create', $this->fields(['departmentId' => 11]))->assertStatus(403);
        $this->form($this->cit, 'update', $this->fields(['departmentId' => 11, 'id' => $cba->id, 'name' => 'Hijack']))->assertStatus(403);
        $this->action($this->cit, 'reorder', ['departmentId' => 11, 'placements' => [['id' => $cba->id, 'parentId' => null, 'order' => 9]]])->assertStatus(403);
        $this->action($this->cit, 'delete', ['departmentId' => 11, 'id' => $cba->id])->assertStatus(403);
        $this->assertSame(['Boss', 1], [$cba->fresh()->name, $cba->fresh()->order]);
        $this->assertSame(1, Member::query()->count());
    }

    public function test_a_member_is_looked_up_inside_the_named_department_only(): void
    {
        $cba = $this->member(11, 'Boss');
        $this->action($this->cit, 'delete', ['departmentId' => 10, 'id' => $cba->id])->assertStatus(404);
        $this->form($this->cit, 'update', $this->fields(['id' => $cba->id, 'name' => 'Hijack']))->assertStatus(404);
        $this->action($this->admin, 'delete', ['departmentId' => 99, 'id' => $cba->id])->assertStatus(404);
        $this->assertSame('Boss', $cba->fresh()->name);
    }

    public function test_create_stores_the_member_and_its_portrait(): void
    {
        $boss = $this->member(10, 'Boss');
        $response = $this->form($this->cit, 'create', $this->fields(['name' => '  Ada  ', 'parentId' => $boss->id, 'order' => '2', 'image' => UploadedFile::fake()->image('ada.png', 80, 80)]))->assertOk();
        $member = Member::query()->find($response->json('data.id'));
        $this->assertSame(['Ada', 'Dean', 10, $boss->id, 2], [$member->name, $member->role, $member->department_id, $member->parent_id, $member->order]);
        $this->assertTrue($this->has($member->image_path));
        $response->assertJsonPath('data.order', 2)->assertJsonPath('data.imageUrl', "/storage/{$member->image_path}");
    }

    public function test_create_without_a_portrait_or_parent(): void
    {
        $this->form($this->admin, 'create', $this->fields())->assertOk()->assertJsonPath('data.imageUrl', null);
        $this->assertNull(Member::query()->first()->parent_id);
    }

    public function test_input_messages_match_the_ts_schema(): void
    {
        $messages = fn (array $over, string $field) => $this->form($this->admin, 'create', $this->fields($over))->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError')->json("error.fields.{$field}.0");
        $this->assertSame('The name is required.', $messages(['name' => '  '], 'name'));
        $this->assertSame('The role is required.', $messages(['role' => ''], 'role'));
        $this->assertSame('The order must be a number.', $messages(['order' => 'first'], 'order'));
        $this->assertSame('The file must be a JPG, PNG, WebP, GIF or AVIF image.', $messages(['image' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')], 'image'));
        $this->assertSame(0, Member::query()->count());
    }

    public function test_parent_rules(): void
    {
        $cba = $this->member(11, 'Stranger');
        $boss = $this->member(10, 'Boss');
        $report = $this->member(10, 'Report', $boss->id);
        $this->form($this->admin, 'create', $this->fields(['parentId' => $cba->id]))->assertStatus(400)->assertJsonPath('error.message', 'The selected parent does not belong to this department.');
        $this->form($this->admin, 'update', $this->fields(['id' => $boss->id, 'parentId' => $report->id]))->assertStatus(400)
            ->assertJsonPath('error.message', 'A member cannot report to itself or to one of its own subordinates.');
        $this->form($this->admin, 'update', $this->fields(['id' => $boss->id, 'parentId' => $boss->id]))->assertStatus(400);
        $this->assertNull($boss->fresh()->parent_id);
    }

    public function test_update_replaces_the_portrait_and_removes_the_old_file(): void
    {
        $old = $this->stored('members/old.webp');
        $member = $this->member(10, 'Ada', null, 1, $old);
        $response = $this->form($this->cit, 'update', $this->fields(['id' => $member->id, 'name' => 'Ada L.', 'order' => 4, 'image' => UploadedFile::fake()->image('new.png', 80, 80)]))->assertOk();
        $member->refresh();
        $this->assertSame(['Ada L.', 4], [$member->name, $member->order]);
        $this->assertNotSame($old, $member->image_path);
        $this->assertFalse($this->has($old));
        $this->assertTrue($this->has($member->image_path));
        $response->assertJsonPath('data.order', 4)->assertJsonPath('data.imageUrl', "/storage/{$member->image_path}");

        // Without a new image the portrait stays.
        $this->form($this->cit, 'update', $this->fields(['id' => $member->id, 'name' => 'Ada']))->assertOk();
        $this->assertSame($member->image_path, $member->fresh()->image_path);
    }

    public function test_reorder_applies_the_placements_in_one_transaction(): void
    {
        $a = $this->member(10, 'A', null, 1);
        $b = $this->member(10, 'B', null, 2);
        $c = $this->member(10, 'C', $b->id, 1);
        $this->action($this->cit, 'reorder', ['departmentId' => 10, 'placements' => [['id' => $a->id, 'parentId' => $b->id, 'order' => 2], ['id' => $b->id, 'parentId' => null, 'order' => 1]]])
            ->assertOk()->assertJsonPath('data.count', 2);
        $this->assertSame([[$b->id, 2], [null, 1], [$b->id, 1]], [[$a->fresh()->parent_id, $a->fresh()->order], [$b->fresh()->parent_id, $b->fresh()->order], [$c->fresh()->parent_id, $c->fresh()->order]]);
    }

    public function test_reorder_rejects_an_invalid_payload_and_changes_nothing(): void
    {
        $a = $this->member(10, 'A', null, 1);
        $b = $this->member(10, 'B', $a->id, 1);
        $stranger = $this->member(11, 'S', null, 1);
        $before = Member::query()->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray();

        $cases = [
            [[['id' => $stranger->id, 'parentId' => null, 'order' => 5]], 'A member does not belong to this department.'],
            [[['id' => $a->id, 'parentId' => $stranger->id, 'order' => 5]], 'The selected parent does not belong to this department.'],
            [[['id' => $a->id, 'parentId' => null, 'order' => 5], ['id' => $a->id, 'parentId' => null, 'order' => 6]], 'A member is listed more than once.'],
            [[['id' => $a->id, 'parentId' => $a->id, 'order' => 5]], 'A member cannot report to itself.'],
            [[['id' => $a->id, 'parentId' => $b->id, 'order' => 5]], 'A member cannot report to itself or to one of its own subordinates.'],
        ];
        foreach ($cases as [$placements, $message]) {
            $this->action($this->admin, 'reorder', ['departmentId' => 10, 'placements' => $placements])->assertStatus(400)->assertJsonPath('error.message', $message);
        }
        $this->assertSame($before, Member::query()->orderBy('id')->get(['id', 'parent_id', 'order'])->toArray());
        $this->action($this->admin, 'reorder', ['departmentId' => 10, 'placements' => []])->assertStatus(400)->assertJsonPath('error.fields.placements.0', 'Too small: expected array to have >=1 items');
    }

    public function test_delete_moves_direct_reports_up_and_removes_the_portrait(): void
    {
        $image = $this->stored('members/boss.webp');
        $dean = $this->member(10, 'Dean', null, 1);
        $head = $this->member(10, 'Head', $dean->id, 1, $image);
        $staff = $this->member(10, 'Staff', $head->id, 1);
        $sibling = $this->member(10, 'Sibling', $dean->id, 2);

        $this->action($this->cit, 'delete', ['departmentId' => 10, 'id' => $head->id])->assertOk()->assertJsonPath('data.id', $head->id);

        $this->assertNull(Member::query()->find($head->id));
        $this->assertFalse($this->has($image));
        $this->assertSame([$dean->id, 1], [$staff->fresh()->parent_id, $staff->fresh()->order]);
        $this->assertSame([$dean->id, 2], [$sibling->fresh()->parent_id, $sibling->fresh()->order]);
    }

    public function test_reorder_rejects_numeric_strings(): void
    {
        $a = $this->member(10, 'A');
        foreach ([['id' => (string) $a->id, 'parentId' => null, 'order' => 1], ['id' => $a->id, 'parentId' => '1', 'order' => 1], ['id' => $a->id, 'parentId' => null, 'order' => '1']] as $p) {
            $this->action($this->admin, 'reorder', ['departmentId' => 10, 'placements' => [$p]])->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError');
        }
    }
}
