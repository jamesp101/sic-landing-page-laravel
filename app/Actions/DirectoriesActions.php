<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Models\DownloadDirectory;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `directories.*` actions (JSON): the Download folders. */
class DirectoriesActions
{
    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'name' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The folder name is required.']),
            'departmentId' => ActionField::int(['nullable', 'integer']),
        ]);
        $actor = $request->user();
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);
        $directory = DownloadDirectory::query()->create(['name' => $input['name'], 'department_id' => $departmentId]);
        Flash::success('Folder created successfully.');

        return ['id' => $directory->id];
    }

    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var DownloadDirectory $directory */
        $directory = DepartmentAccess::loadScoped($actor, DownloadDirectory::class, $input['id']);
        $directory->delete();
        Flash::success('Folder deleted.');

        return ['id' => $input['id']];
    }
}
