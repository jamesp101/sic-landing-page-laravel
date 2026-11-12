<?php

namespace App\Actions;

use App\Admin\ActionInput;
use App\Admin\DepartmentAccess;
use App\Models\Download;
use App\Site\Downloads;
use App\Storage\DownloadIntake;
use App\Storage\StoredFiles;
use App\Support\Authz;
use App\Support\Flash;
use Illuminate\Http\Request;

/** `downloads.*` actions (multipart create, JSON delete). */
class DownloadsActions
{
    public function create(Request $request): array
    {
        $input = ActionInput::validate(
            $request,
            ['fileName' => 'string', 'file' => 'raw', 'uploadedKey' => 'string', 'tags' => 'raw', 'directoryName' => 'string', 'departmentId' => 'int'],
            [
                'fileName' => ['required', 'string', 'max:255'],
                // Multipart upload (local driver).
                'file' => ['nullable', ...DownloadIntake::downloadFile()],
                // Key of a file the browser already uploaded straight to the S3 bucket.
                'uploadedKey' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'string'],
                'directoryName' => ['nullable', 'string', 'max:255'],
                'departmentId' => ['nullable', 'integer'],
            ],
            ['fileName.required' => 'The display name is required.'],
        );
        $actor = Authz::requireUser($request->user());
        $departmentId = DepartmentAccess::resolveWriteDepartment($actor, $input['departmentId'] ?? null);

        ['key' => $key, 'size' => $size] = DownloadIntake::acceptDownloadFile($actor->id, [
            'file' => $input['file'] ?? null,
            'uploadedKey' => $input['uploadedKey'] ?? null,
        ]);
        $download = StoredFiles::commitWithFiles([
            'adopted' => [$key],
            'run' => fn () => Download::query()->create([
                'file_name' => $input['fileName'],
                'file_path' => $key,
                'file_size' => $size,
                'tags' => Downloads::encodeTags($input['tags'] ?? null),
                'directory' => ($input['directoryName'] ?? '') !== '' ? $input['directoryName'] : Downloads::DEFAULT_DIRECTORY,
                'department_id' => $departmentId,
                'user_id' => $actor->id,
            ]),
        ]);
        Flash::success('File uploaded successfully.');

        return ['id' => $download->id];
    }

    public function delete(Request $request): array
    {
        $input = ActionInput::validate($request, ['id' => 'int'], ['id' => ['required', 'integer']]);
        $actor = Authz::requireUser($request->user());
        /** @var Download $download */
        $download = DepartmentAccess::loadScoped($actor, Download::class, $input['id']);
        StoredFiles::deleteWithFiles(fn () => $download->delete(), [$download->file_path]);
        Flash::success('File deleted successfully.');

        return ['id' => $input['id']];
    }
}
