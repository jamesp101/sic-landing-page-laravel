<?php

namespace App\Admin;

use App\Downloads\DownloadRules;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Models\User;
use App\Site\Seo;
use App\Support\Authz;

/** The admin Downloads list's view models: a Download row and the folder map. Storage rules live in DownloadRules. */
final class AdminDownloads
{
    /**
     * A Download row of the admin list, as the Downloads manager renders it. The row's `department` relation must be loaded.
     *
     * @return array{id: int, fileName: string, url: string, size: int, extension: string, kind: string, directory: string, tags: list<string>, departmentId: ?int, departmentName: ?string, createdAt: string}
     */
    public static function toAdminDownload(Download $row): array
    {
        ['url' => $url, 'extension' => $extension, 'kind' => $kind] = DownloadRules::downloadFacts($row);

        return [
            'id' => $row->id,
            'fileName' => $row->file_name,
            'url' => $url,
            'size' => $row->file_size,
            'extension' => $extension,
            'kind' => $kind,
            'directory' => DownloadRules::directoryOf($row->directory),
            'tags' => DownloadRules::parseTags($row->tags),
            'departmentId' => $row->department_id,
            'departmentName' => $row->department?->name,
            'createdAt' => Seo::iso($row->created_at),
        ];
    }

    /**
     * The admin folder map for (actor, scope): every stored Directory plus every Directory in use, with its file count,
     * and the stored total. Counts and total cover the whole scope, not the page or the search. Shared Directories (no
     * department) stay listed under a department scope. `$href` builds a folder's link.
     *
     * @param  callable(string): string  $href
     * @return array{totalFiles: int, totalBytes: int, folders: list<array{name: string, count: int, id: ?int, canDelete: bool, href: string}>}
     */
    public static function getAdminFolders(User $actor, array $scope, callable $href): array
    {
        $groups = Download::query()
            ->where(AdminScope::departmentWhere($actor, $scope))
            ->groupBy('directory')
            ->selectRaw('directory, count(*) as total, sum(file_size) as bytes')
            ->get();
        $directories = DownloadDirectory::query()
            ->where(AdminScope::departmentWhere($actor, $scope, ['includeGeneral' => true]))
            ->orderBy('name')->get();

        $map = [];
        foreach ($directories as $d) {
            $map[$d->name] = [
                'name' => $d->name,
                'count' => 0,
                'id' => $d->id,
                'canDelete' => Authz::isSuperAdmin($actor) || $d->department_id === $actor->department_id,
            ];
        }
        $totalFiles = 0;
        $totalBytes = 0;
        foreach ($groups as $group) {
            $name = DownloadRules::directoryOf($group->directory);
            $existing = $map[$name] ?? ['name' => $name, 'count' => 0, 'id' => null, 'canDelete' => false];
            $map[$name] = [...$existing, 'count' => $existing['count'] + (int) $group->total];
            $totalFiles += (int) $group->total;
            $totalBytes += (int) $group->bytes;
        }

        return [
            'totalFiles' => $totalFiles,
            'totalBytes' => $totalBytes,
            'folders' => array_map(fn (array $folder) => [...$folder, 'href' => $href($folder['name'])], array_values($map)),
        ];
    }
}
