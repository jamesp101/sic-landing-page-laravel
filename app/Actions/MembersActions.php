<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\MemberRules;
use App\Admin\OrgTree;
use App\Models\Member;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\Authz;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** `members.*` actions (multipart for create/update, JSON for reorder/delete). */
class MembersActions
{
    private const TYPES = ['departmentId' => 'int', 'name' => 'string', 'role' => 'string', 'parentId' => 'int', 'order' => 'int', 'image' => 'raw'];

    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        $actor = Authz::requireUser($request->user());
        DepartmentAccess::loadOwnDepartment($actor, $input['departmentId']);
        MemberRules::assertValidParent($input['departmentId'], $input['parentId'] ?? null);

        $image = $this->image($input);
        /** @var Member $member */
        $member = StoredFiles::commitWithFiles([
            'files' => ['image' => $image],
            'run' => fn (array $keys) => Member::query()->create([
                'name' => $input['name'],
                'role' => $input['role'],
                'department_id' => $input['departmentId'],
                'parent_id' => $input['parentId'] ?? null,
                'order' => $input['order'],
                'image_path' => $keys['image'] ?? null,
            ]),
        ]);

        return ['id' => $member->id, 'order' => $member->order, 'imageUrl' => Storage::publicUrl($member->image_path)];
    }

    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = Authz::requireUser($request->user());
        $member = DepartmentAccess::loadMember($actor, $input['departmentId'], $input['id']);
        MemberRules::assertValidParent($member->department_id, $input['parentId'] ?? null, $member->id);

        $image = $this->image($input);
        $previous = $member->image_path;
        /** @var Member $saved */
        $saved = StoredFiles::commitWithFiles([
            'files' => ['image' => $image],
            'run' => function (array $keys) use ($member, $input): Member {
                $member->update([
                    'name' => $input['name'],
                    'role' => $input['role'],
                    'parent_id' => $input['parentId'] ?? null,
                    'order' => $input['order'],
                    'image_path' => $keys['image'] ?? $member->image_path,
                ]);

                return $member;
            },
            'stale' => [$image ? $previous : null],
        ]);

        return ['id' => $member->id, 'order' => $input['order'], 'imageUrl' => Storage::publicUrl($saved->image_path)];
    }

    /** Applies the listed parent/order placements of one department's members in a single transaction. */
    public function reorder(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['departmentId' => 'int', 'placements' => 'raw'],
            [
                'departmentId' => ['required', 'integer'],
                'placements' => ['required', 'array', 'min:1', 'max:1000'],
                'placements.*.id' => ['required', self::jsonInt()],
                'placements.*.parentId' => ['present', 'nullable', self::jsonInt()],
                'placements.*.order' => ['required', self::jsonInt(), 'numeric', 'min:0'],
            ],
            [
                'placements.required' => 'Too small: expected array to have >=1 items',
                'placements.min' => 'Too small: expected array to have >=1 items',
                'placements.max' => 'Too big: expected array to have <=1000 items',
                'placements.*.order.min' => 'Too small: expected number to be >=0',
            ],
        );
        $departmentId = $input['departmentId'];
        DepartmentAccess::loadOwnDepartment(Authz::requireUser($request->user()), $departmentId);
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
    public function delete(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['departmentId' => 'int', 'id' => 'int'],
            ['departmentId' => ['required', 'integer'], 'id' => ['required', 'integer']],
        );
        $actor = Authz::requireUser($request->user());
        $member = DepartmentAccess::loadMember($actor, $input['departmentId'], $input['id']);
        StoredFiles::deleteWithFiles(
            fn () => DB::transaction(function () use ($member, $input): void {
                foreach (OrgTree::reparentOnDelete(MemberRules::tree($input['departmentId']), $member->id) as $p) {
                    Member::query()->whereKey($p['id'])->update(['parent_id' => $p['parentId'], 'order' => $p['order']]);
                }
                $member->delete();
            }),
            [$member->image_path],
        );

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        $types = self::TYPES;
        $rules = [
            'departmentId' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'max:255'],
            'parentId' => ['nullable', 'integer'],
            'order' => ['required', 'integer'],
            'image' => ['nullable', ...ImageFile::imageFile(Storage::limit('memberImage'), 'portrait')],
        ];
        if ($withId) {
            $types['id'] = 'int';
            $rules['id'] = ['required', 'integer'];
        }

        return ActionInput::validate($request, $types, $rules, [
            'name.required' => 'The name is required.',
            'name.max' => 'Too big: expected string to have <=255 characters',
            'role.max' => 'Too big: expected string to have <=255 characters',
            'role.required' => 'The role is required.',
            'order.required' => 'The order must be a number.',
            'order.integer' => 'The order must be a number.',
        ]);
    }

    /** @return array{prefix: string, file: UploadedFile, variant: true}|null */
    private function image(array $input): ?array
    {
        $file = $input['image'] ?? null;

        return $file instanceof UploadedFile
            ? ['prefix' => 'members', 'file' => ImageFile::upload($file, 'portrait'), 'variant' => true]
            : null;
    }

    /** zod `z.number().int()` on JSON input: numeric strings are rejected. */
    private static function jsonInt(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value)) {
                $fail('Invalid input: expected number, received '.get_debug_type($value));
            }
        };
    }
}
