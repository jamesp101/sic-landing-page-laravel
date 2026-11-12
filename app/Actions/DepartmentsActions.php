<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\Slug;
use App\Content\ContentField;
use App\Models\Department;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\Authz;
use App\Support\Flash;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** `departments.*` actions (multipart for create/update, JSON for delete). */
class DepartmentsActions
{
    public function create(Request $request): array
    {
        $input = $this->input($request, false);
        Authz::requireSuperAdmin($request->user());

        if (Department::query()->where('name', $input['name'])->exists()) {
            throw RequestError::conflict('A department with this name already exists.');
        }
        $slug = Slug::uniqueSlug(
            $input['name'],
            fn (string $candidate) => Department::query()->where('slug', $candidate)->exists(),
            'department',
        );

        $department = StoredFiles::commitWithFiles([
            'files' => ['logo' => $this->logo($input)],
            'run' => fn (array $keys) => Department::query()->create([
                'name' => $input['name'],
                'slug' => $slug,
                'description' => $input['description'] ?? null,
                'hero_content' => $input['heroContent'] ?? null,
                'logo_path' => $keys['logo'] ?? null,
            ]),
        ]);
        Flash::success('Department created successfully.');

        return ['id' => $department->id];
    }

    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = Authz::requireUser($request->user());
        $row = DepartmentAccess::loadOwnDepartment($actor, $input['id']);
        $logo = $this->logo($input);

        StoredFiles::commitWithFiles([
            'files' => ['logo' => $logo],
            'run' => fn (array $keys) => $row->update([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'hero_content' => $input['heroContent'] ?? null,
                'logo_path' => $keys['logo'] ?? $row->logo_path,
            ]),
            'stale' => [$logo ? $row->logo_path : null],
        ]);
        Flash::success('Department details updated.');

        return ['id' => $row->id];
    }

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        Authz::requireSuperAdmin($request->user());
        $row = Department::query()
            ->with([
                'members:id,department_id,image_path',
                'carouselItems:id,department_id,image_path,video_path',
                'posts:id,department_id,highlight_image_path',
                'downloads:id,department_id,file_path',
            ])
            ->find($input['id']);
        if (! $row) {
            throw RequestError::notFound();
        }

        StoredFiles::deleteWithFiles(fn () => $row->delete(), [
            $row->logo_path,
            ...$row->members->pluck('image_path')->all(),
            ...$row->carouselItems->flatMap(fn ($item) => [$item->image_path, $item->video_path])->all(),
            ...$row->posts->pluck('highlight_image_path')->all(),
            ...$row->downloads->pluck('file_path')->all(),
        ]);
        Flash::success('Department deleted successfully.');

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        $types = ['name' => 'string', 'description' => 'raw', 'heroContent' => 'text', 'logo' => 'raw'];
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'heroContent' => ['nullable', ...ContentField::rules(false)],
            'logo' => ['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'logo')],
        ];
        if ($withId) {
            $types['id'] = 'int';
            $rules['id'] = ['required', 'integer'];
        }
        $input = ActionInput::validate($request, $types, $rules, ['name.required' => 'The name is required.']);
        // The form always sends both fields: an emptied one is stored as '' (NULL only when the field is absent).
        $input['heroContent'] = $request->has('heroContent') ? ContentField::normalized($input['heroContent'] ?? '') : null;
        $input['description'] = $request->has('description') ? ($input['description'] ?? '') : null;

        return $input;
    }

    private function logo(array $input): ?array
    {
        $file = $input['logo'] ?? null;

        return $file instanceof UploadedFile
            ? ['prefix' => 'departments', 'file' => ImageFile::upload($file, 'logo'), 'variant' => true]
            : null;
    }
}
