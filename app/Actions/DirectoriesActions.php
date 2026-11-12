<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Models\DownloadDirectory;
use App\Support\Authz;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `directories.*` actions (JSON): the Download folders. */
class DirectoriesActions
{
    public function create(Request $request): array
    {
        $input = ActionInput::validate($request, ['name' => 'string', 'departmentId' => 'int'], [
            'name' => ['required', 'string', 'max:255'],
            'departmentId' => ['nullable', 'integer'],
        ], ['name.required' => 'The folder name is required.']);
        $actor = Authz::requireUser($request->user());
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $directory = DownloadDirectory::query()->create(['name' => $input['name'], 'department_id' => $departmentId]);
        Flash::success('Folder created successfully.');

        return ['id' => $directory->id];
    }

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var DownloadDirectory $directory */
        $directory = DepartmentAccess::loadScoped($actor, DownloadDirectory::class, $input['id']);
        $directory->delete();
        Flash::success('Folder deleted.');

        return ['id' => $input['id']];
    }
}
