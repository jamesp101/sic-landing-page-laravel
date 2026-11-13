<?php

namespace App\Actions;

use App\Admin\ActionField;
use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Downloads\DownloadRules;
use App\Models\Download;
use App\Storage\DownloadIntake;
use App\Storage\StoredFiles;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `downloads.*` actions (multipart create, JSON delete). */
class DownloadsActions
{
    #[Action(Access::User)]
    public function create(Request $request): array
    {
        $input = ActionInput::validate($request, [
            'fileName' => ActionField::string(['required', 'string', 'max:255'], ['required' => 'The display name is required.']),
            // Multipart upload (local driver).
            'file' => ActionField::raw(['nullable', ...DownloadIntake::downloadFile()]),
            // Key of a file the browser already uploaded straight to the S3 bucket.
            'uploadedKey' => ActionField::string(['nullable', 'string', 'max:255']),
            'tags' => ActionField::raw(['nullable', 'string']),
            'directoryName' => ActionField::string(['nullable', 'string', 'max:255']),
            'departmentId' => ActionField::int(['nullable', 'integer']),
        ]);
        $actor = $request->user();
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);

        ['key' => $key, 'size' => $size] = DownloadIntake::acceptDownloadFile($actor->id, [
            'file' => $input['file'] ?? null,
            'uploadedKey' => $input['uploadedKey'] ?? null,
        ]);
        // The key is already stored (`DownloadIntake`); the save removes it again if the row cannot be written.
        $download = StoredFiles::save(new Download, [
            'file_name' => $input['fileName'],
            'file_path' => $key,
            'file_size' => $size,
            'tags' => DownloadRules::encodeTags($input['tags'] ?? null),
            'directory' => ($input['directoryName'] ?? '') !== '' ? $input['directoryName'] : DownloadRules::DEFAULT_DIRECTORY,
            'department_id' => $departmentId,
            'user_id' => $actor->id,
        ]);
        Flash::success('File uploaded successfully.');

        return ['id' => $download->id];
    }

    #[Action(Access::User)]
    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => ActionField::id()]);
        $actor = $request->user();
        /** @var Download $download */
        $download = DepartmentAccess::loadScoped($actor, Download::class, $input['id']);
        StoredFiles::delete($download);
        Flash::success('File deleted successfully.');

        return ['id' => $input['id']];
    }
}
