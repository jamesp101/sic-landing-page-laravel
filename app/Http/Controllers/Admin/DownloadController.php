<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminDownloads;
use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Downloads\DownloadRules;
use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Storage\DownloadIntake;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
    public function index(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);
        $scope = AdminScope::current($request);

        // Each filter is declared once: its query parameter, how it parses, how a value narrows the query.
        $filters = [
            'folder' => AdminList::listFilter(
                fn (?string $raw) => $raw !== null && $raw !== '' ? $raw : null,
                fn (string $folder) => DownloadRules::directoryWhere($folder),
            ),
        ];

        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => $scope,
            'spec' => [
                'fields' => [
                    'fileName' => AdminList::sortColumn('file_name', 'asc', 'Name'),
                    'createdAt' => AdminList::sortColumn('created_at', 'desc', 'Date'),
                    'size' => AdminList::sortColumn('file_size', 'desc', 'Size'),
                ],
                'defaultSort' => 'createdAt',
                'tiebreak' => AdminList::orderColumn('id', 'desc'),
                'search' => ['file_name', 'directory', 'tags'],
                'perPage' => 24,
            ],
            'filters' => $filters,
            'count' => fn (Closure $where) => Download::query()->where($where)->count(),
            'fetch' => fn (array $args) => AdminList::apply(Download::query()->with('department:id,name'), $args)->get(),
        ]);

        ['totalFiles' => $totalFiles, 'totalBytes' => $totalBytes, 'folders' => $folders] = AdminDownloads::getAdminFolders(
            $actor,
            $scope,
            fn (string $name) => $list->href(['folder' => $name]),
        );

        $downloads = collect($list->rows)->map(fn (Download $row) => AdminDownloads::toAdminDownload($row))->values()->all();

        $manager = [
            'totalFiles' => $totalFiles,
            'totalBytes' => $totalBytes,
            'matching' => $list->total,
            'q' => $list->q,
            'folder' => $list->values['folder'] ?? '',
            'filtered' => $list->filtered,
            'sort' => $list->sort,
            'dir' => $list->dir,
            'sorts' => $list->sortOptions(),
            'allFoldersHref' => $list->clearFilterHref('folder'),
            'resetHref' => $list->resetHref(),
            'folders' => $folders,
        ];

        return view('admin.downloads.index', [
            'list' => $list,
            'downloads' => $downloads,
            'departmentField' => DepartmentAccess::createDepartmentField($actor, $request),
            'manager' => $manager,
        ]);
    }

    public function create(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        return view('admin.downloads.create', [
            'departmentField' => DepartmentAccess::createDepartmentField($actor, $request),
            'directories' => DownloadRules::getDirectoryNames(),
            'defaultDirectory' => DownloadRules::DEFAULT_DIRECTORY,
            'maxMb' => DownloadIntake::DOWNLOAD_MAX_MB,
            'uploadMode' => DownloadIntake::downloadUploadMode(),
        ]);
    }
}
