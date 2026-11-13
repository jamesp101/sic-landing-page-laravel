<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\MemberRules;
use App\Models\Member;
use App\OrgChart\OrgTree;
use App\Storage\ImageFile;
use App\Storage\ImageUpload;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** `members.*` actions (multipart for create/update, JSON for reorder/delete). */
class MembersActions
{
    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = $request->user();
        DepartmentAccess::loadOwnDepartment($actor, $input['departmentId']);
        MemberRules::assertValidParent($input['departmentId'], $input['parentId'] ?? null);

        /** @var Member $member */
        $member = StoredFiles::save(new Member, [
            'name' => $input['name'],
            'role' => $input['role'],
            'department_id' => $input['departmentId'],
            'parent_id' => $input['parentId'] ?? null,
            'order' => $input['order'],
        ], ['image_path' => $this->image($input)]);

        return ['id' => $member->id, 'order' => $member->order, 'imageUrl' => Storage::publicUrl($member->image_path)];
    }

    #[Action(Access::User)]
    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = $request->user();
        $member = DepartmentAccess::loadMember($actor, $input['departmentId'], $input['id']);
        MemberRules::assertValidParent($member->department_id, $input['parentId'] ?? null, $member->id);

        /** @var Member $saved */
        $saved = StoredFiles::save($member, [
            'name' => $input['name'],
            'role' => $input['role'],
            'parent_id' => $input['parentId'] ?? null,
            'order' => $input['order'],
        ], ['image_path' => $this->image($input)]);

        return ['id' => $member->id, 'order' => $input['order'], 'imageUrl' => Storage::publicUrl($saved->image_path)];
    }

    /** Applies the listed parent/order placements of one department's members in a single transaction. */
    #[Action(Access::User)]
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'departmentId' => ActionField::int(['required', 'integer']),
            'placements' => ActionField::list(1000, [
                '*.id' => ['required', ActionField::jsonInt()],
                '*.parentId' => ['present', 'nullable', ActionField::jsonInt()],
                '*.order' => ['required', ActionField::jsonInt(), 'numeric', 'min:0'],
            ], ['*.order.min' => 'Too small: expected number to be >=0']),
        ]);
        $departmentId = $input['departmentId'];
        DepartmentAccess::loadOwnDepartment($request->user(), $departmentId);
        $placements = array_map(fn (array $p): array => [
            'id' => (int) $p['id'],
            'parentId' => $p['parentId'] === null ? null : (int) $p['parentId'],
            'order' => (int) $p['order'],
        ], array_values($input['placements']));

        DB::transaction(function () use ($departmentId, $placements): void {
            $problem = OrgTree::placementsError(MemberRules::tree($departmentId), $placements);
            if ($problem !== null) {
                throw RequestError::badRequest($problem);
            }
            foreach ($placements as $p) {
                Member::query()->whereKey($p['id'])->update(['parent_id' => $p['parentId'], 'order' => $p['order']]);
            }
        });

        return ['count' => count($placements)];
    }

    /** Deletes a member; their direct reports move up to the member's manager (or become top level). */
    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['departmentId' => ActionField::int(['required', 'integer']), 'id' => ActionField::id()]);
        $actor = $request->user();
        $member = DepartmentAccess::loadMember($actor, $input['departmentId'], $input['id']);
        StoredFiles::delete($member, fn () => DB::transaction(function () use ($member, $input): void {
            foreach (OrgTree::reparentOnDelete(MemberRules::tree($input['departmentId']), $member->id) as $p) {
                Member::query()->whereKey($p['id'])->update(['parent_id' => $p['parentId'], 'order' => $p['order']]);
            }
            $member->delete();
        }));

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        return ActionInput::validate($request, [
            'departmentId' => ActionField::int(['required', 'integer']),
            'name' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The name is required.']),
            'role' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The role is required.']),
            'parentId' => ActionField::int(['nullable', 'integer']),
            'order' => ActionField::int(['required', 'integer'], ['required' => 'The order must be a number.', 'integer' => 'The order must be a number.']),
            'image' => ActionField::raw(['nullable', ...ImageFile::imageFile(Storage::limit('memberImage'), 'portrait')]),
        ] + ($withId ? ['id' => ActionField::id()] : []));
    }

    /** The replacement portrait, or null to keep the current one. */
    private function image(array $input): ?ImageUpload
    {
        $file = $input['image'] ?? null;

        return $file instanceof UploadedFile ? ImageFile::upload($file, 'portrait') : null;
    }
}
