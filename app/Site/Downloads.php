<?php

namespace App\Site;

use App\Downloads\DownloadRules;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Storage\Storage;
use App\Support\Files;
use App\Support\Search;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public `/downloads` page. Storage rules (Tags, Directory default, File kind) live in DownloadRules.
 *
 * Download view: ['id', 'fileName', 'url', 'downloadUrl', 'size', 'extension', 'kind', 'tags', 'directory', 'createdAt', 'department' => ?['id','name','slug']].
 */
final class Downloads
{
    /**
     * The public `/downloads` page: the Downloads matching the search and Department, the Directories with how many
     * of those each holds, and the list narrowed to `directory`. Not paginated: the page groups files by Directory and
     * shows a count per group, which a page boundary would cut through.
     *
     * @param  array{search?: ?string, departmentId?: ?int, directory?: ?string}  $filters
     * @return array{layout: array, downloads: list<array>, groups: list<array{name: string, files: list<array>}>, folders: list<array{name: string, count: int, href: string}>, selected: string, allHref: string, totalCount: int, seo: array}
     */
    public static function getDownloadsPage(array $filters): array
    {
        $search = $filters['search'] ?? null;
        $departmentId = $filters['departmentId'] ?? null;
        $directory = $filters['directory'] ?? null;
        $layout = Queries::layout();

        $matching = self::listDownloads($search, $departmentId);
        $counts = array_fill_keys(self::getDownloadDirectories($departmentId), 0);
        foreach ($matching as $file) {
            $counts[$file['directory']] = ($counts[$file['directory']] ?? 0) + 1;
        }
        $folders = [];
        foreach ($counts as $name => $count) {
            $folders[] = [
                'name' => (string) $name,
                'count' => $count,
                'href' => PostFilters::downloadsHref(['search' => $search, 'departmentId' => $departmentId, 'directory' => (string) $name]),
            ];
        }

        $downloads = $directory !== null && $directory !== '' ? array_values(array_filter($matching, fn (array $f) => $f['directory'] === $directory)) : $matching;
        $grouped = [];
        foreach ($downloads as $file) {
            $grouped[$file['directory']][] = $file;
        }
        $groups = [];
        foreach ($grouped as $name => $files) {
            $groups[] = ['name' => (string) $name, 'files' => $files];
        }

        return [
            'layout' => $layout,
            'downloads' => $downloads,
            'groups' => $groups,
            'folders' => $folders,
            'selected' => $directory ?? '',
            'allHref' => PostFilters::downloadsHref(['search' => $search, 'departmentId' => $departmentId]),
            'totalCount' => count($matching),
            'seo' => [
                'title' => 'Downloads',
                'description' => 'Official forms, academic guides, curriculum checklists and schedules from San Isidro College, organised by folder and department.',
                'canonical' => PostFilters::downloadsHref([]),
                'noindex' => PostFilters::hasDownloadFilters($filters),
                'follow' => true,
                'breadcrumbs' => [['label' => 'Home', 'href' => '/'], ['label' => 'Downloads']],
            ],
        ];
    }

    /** @return list<array> */
    private static function listDownloads(?string $search, ?int $departmentId): array
    {
        $query = Download::query()
            ->select(['id', 'file_name', 'file_path', 'file_size', 'tags', 'directory', 'created_at', 'department_id'])
            ->with('department:id,name,slug');
        if ($departmentId) {
            $query->where('department_id', $departmentId);
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $like = Search::textContains($search);
            $query->where(fn (Builder $q) => $q
                ->whereRaw("file_name like ? escape '!'", [$like])
                ->orWhereRaw("tags like ? escape '!'", [$like])
                ->orWhereRaw("directory like ? escape '!'", [$like]));
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get()->map(function (Download $row): array {
            ['url' => $url, 'extension' => $extension, 'kind' => $kind] = DownloadRules::downloadFacts($row);

            return [
                'id' => $row->id,
                'fileName' => $row->file_name,
                'url' => $url,
                'downloadUrl' => Storage::downloadUrl(
                    $row->file_path,
                    $row->file_name.(Files::extensionOf($row->file_name) || ! $extension ? '' : ".{$extension}"),
                ),
                'size' => $row->file_size,
                'extension' => $extension,
                'kind' => $kind,
                'tags' => DownloadRules::parseTags($row->tags),
                'directory' => DownloadRules::directoryOf($row->directory),
                'createdAt' => $row->created_at,
                'department' => $row->department ? ['id' => $row->department->id, 'name' => $row->department->name, 'slug' => $row->department->slug] : null,
            ];
        })->all();
    }

    /** @return list<string> */
    private static function getDownloadDirectories(?int $departmentId): array
    {
        $custom = DownloadDirectory::query()->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId))->pluck('name');
        $used = Download::query()->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->whereNotNull('directory')->where('directory', '!=', '')->distinct()->pluck('directory');
        $names = collect([DownloadRules::DEFAULT_DIRECTORY])->merge($custom)->merge($used)->unique()->values()->all();

        $collator = class_exists(\Collator::class) ? new \Collator('en') : null;
        usort($names, fn (string $a, string $b) => $collator ? $collator->compare($a, $b) : strcasecmp($a, $b));

        return $names;
    }
}
