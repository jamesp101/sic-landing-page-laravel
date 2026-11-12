<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminList;
use App\Admin\AdminPage;
use App\Admin\AdminScope;
use App\Admin\DepartmentAccess;
use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Site\Downloads;
use App\Storage\DownloadIntake;
use App\Support\Authz;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DownloadController extends Controller
{
    public function index(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);
        $superadmin = Authz::isSuperAdmin($actor);
        $scope = AdminScope::current($request);

        // Each filter is declared once: its query parameter, how it parses, how a value narrows the query.
        $filters = [
            'folder' => AdminList::listFilter(
                fn (?string $raw) => $raw !== null && $raw !== '' ? $raw : null,
                fn (string $folder) => Downloads::directoryWhere($folder),
            ),
        ];

        $list = AdminList::runList([
            'url' => $request->getRequestUri(),
            'actor' => $actor,
            'scope' => $scope,
            'spec' => [
                'fields' => [
                    'fileName' => AdminList::sortColumn('file_name'),
                    'createdAt' => AdminList::sortColumn('created_at', 'desc'),
                    'size' => AdminList::sortColumn('file_size', 'desc'),
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

        ['totalFiles' => $totalFiles, 'totalBytes' => $totalBytes, 'folders' => $folders] = Downloads::getAdminFolders(
            $actor,
            $scope,
            fn (string $name) => $list->href(['folder' => $name]),
        );
        $departments = DepartmentAccess::departmentOptions($actor);

        $downloads = collect($list->rows)->map(fn (Download $row) => Downloads::toAdminDownload($row))->values()->all();

        $manager = [
            'totalFiles' => $totalFiles,
            'totalBytes' => $totalBytes,
            'matching' => $list->total,
            'q' => $list->q,
            'folder' => $list->values['folder'] ?? '',
            'filtered' => $list->filtered,
            'sort' => $list->sort,
            'dir' => $list->dir,
            'sorts' => array_map(fn (array $s) => [
                ...$s,
                'href' => $list->sortHref($s['field']),
                'active' => $list->sort === $s['field'],
            ], [
                ['label' => 'Name', 'field' => 'fileName'],
                ['label' => 'Date', 'field' => 'createdAt'],
                ['label' => 'Size', 'field' => 'size'],
            ]),
            'allFoldersHref' => $list->href(['folder' => null]),
            'resetHref' => $list->resetHref(),
            'folders' => $folders,
        ];

        return view('admin.downloads.index', [
            'list' => $list,
            'downloads' => $downloads,
            'departments' => $departments,
            'manager' => $manager,
            'superadmin' => $superadmin,
        ]);
    }

    public function create(Request $request): View
    {
        ['actor' => $actor] = AdminPage::resolve($request);

        return view('admin.downloads.create', [
            ...DepartmentAccess::formProps($actor, $request),
            'directories' => Downloads::getDirectoryNames(),
            'defaultDirectory' => Downloads::DEFAULT_DIRECTORY,
            'maxMb' => DownloadIntake::DOWNLOAD_MAX_MB,
            'uploadMode' => DownloadIntake::downloadUploadMode(),
        ]);
    }
}
