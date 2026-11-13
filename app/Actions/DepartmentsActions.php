<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Admin\Slug;
use App\Content\Content;
use App\Content\ContentField;
use App\Models\Department;
use App\Storage\ImageFile;
use App\Storage\ImageUpload;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\Flash;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** `departments.*` actions (multipart for create/update, JSON for delete). */
class DepartmentsActions
{
    #[Action(Access::Superadmin)]
    public function create(Request $request): array
    {
        $input = $this->input($request, false);

        if (Department::query()->where('name', $input['name'])->exists()) {
            throw RequestError::conflict('A department with this name already exists.');
        }
        $slug = Slug::uniqueSlug(
            $input['name'],
            fn (string $candidate) => Department::query()->where('slug', $candidate)->exists(),
            'department',
        );

        $department = StoredFiles::save(
            new Department,
            ['name' => $input['name'], 'slug' => $slug, 'description' => $input['description'] ?? null, 'hero_content' => $input['heroContent'] ?? null],
            ['logo_path' => $this->logo($input)],
        );
        Flash::success('Department created successfully.');

        return ['id' => $department->id];
    }

    #[Action(Access::User)]
    public function update(Request $request): array
    {
        $input = $this->input($request, true);
        $actor = $request->user();
        $row = DepartmentAccess::loadOwnDepartment($actor, $input['id']);

        StoredFiles::save(
            $row,
            ['name' => $input['name'], 'description' => $input['description'] ?? null, 'hero_content' => $input['heroContent'] ?? null],
            ['logo_path' => $this->logo($input)],
        );
        Flash::success('Department details updated.');

        return ['id' => $row->id];
    }

    #[Action(Access::Superadmin)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $row = Department::query()->find($input['id']);
        if (! $row) {
            throw RequestError::notFound();
        }

        // The Department owns its logo, Member portraits, Slide photos, videos and Layer images, Highlight images and Downloads.
        StoredFiles::delete($row);
        Flash::success('Department deleted successfully.');

        return ['id' => $input['id']];
    }

    private function input(Request $request, bool $withId): array
    {
        $input = ActionInput::validate($request, [
            'name' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The name is required.']),
            'description' => ActionField::raw(['nullable', 'string']),
            'heroContent' => ActionField::text(['nullable', ...ContentField::rules(false)]),
            'logo' => ActionField::raw(['nullable', ...ImageFile::imageFile(Storage::limit('image'), 'logo')]),
        ] + ($withId ? ['id' => ActionField::id()] : []));
        // The form always sends both fields: an emptied one is stored as '' (NULL only when the field is absent).
        $input['heroContent'] = $request->has('heroContent') ? Content::forStorage($input['heroContent'])->value : null;
        $input['description'] = $request->has('description') ? ($input['description'] ?? '') : null;

        return $input;
    }

    /** The replacement logo, or null to keep the current one. */
    private function logo(array $input): ?ImageUpload
    {
        $file = $input['logo'] ?? null;

        return $file instanceof UploadedFile ? ImageFile::upload($file, 'logo') : null;
    }
}
