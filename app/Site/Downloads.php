<?php

namespace App\Site;

use App\Admin\AdminScope;
use App\Admin\Search;
use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Models\User;
use App\Storage\Storage;
use App\Support\Authz;
use App\Support\Files;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Downloads: the one place that knows how a Download is stored (Tags as a JSON array in one column, a missing
 * Directory meaning General) and how it is viewed, on the public `/downloads` page and in the admin Downloads list.
 *
 * Download view: ['id', 'fileName', 'url', 'downloadUrl', 'size', 'extension', 'kind', 'tags', 'directory', 'createdAt', 'department' => ?['id','name','slug']].
 */
final class Downloads
{
    /** The Directory of a Download stored without one. */
    public const DEFAULT_DIRECTORY = 'General';

    /** What a Download is stored with when it has no Directory: see `directoryOf`. */
    public static function directoryOf(?string $stored): string
    {
        return $stored === null || $stored === '' ? self::DEFAULT_DIRECTORY : $stored;
    }

    /** The Tags field of the upload form (comma-separated) as the value stored in `downloads.tags`. */
    public static function encodeTags(?string $input): string
    {
        return json_encode(self::splitTags($input ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Tags of a stored value: a JSON array, tolerating comma-separated strings and null.
     *
     * @return list<string>
     */
    public static function parseTags(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        $parsed = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($parsed) && array_is_list($parsed)) {
                return array_values(array_filter(array_map(fn ($tag) => trim(self::tagText($tag)), $parsed), fn (string $t) => $t !== ''));
            }
            if (is_string($parsed)) {
                return self::parseTags($parsed);
            }
        }

        return self::splitTags($trimmed);
    }

    /** Constraint for the Downloads in one Directory; General also holds the ones stored without any. */
    public static function directoryWhere(string $directory): Closure
    {
        return $directory === self::DEFAULT_DIRECTORY
            ? static fn (Builder $q) => $q->where(static fn (Builder $q) => $q->whereNull('directory')->orWhere('directory', $directory))
            : static fn (Builder $q) => $q->where('directory', $directory);
    }

    /**
     * Directory names the upload form offers, by name, each once (Directories of different Departments may share a name).
     *
     * @return list<string>
     */
    public static function getDirectoryNames(): array
    {
        return DownloadDirectory::query()->orderBy('name')->pluck('name')->unique()->values()->all();
    }

    /**
     * What both Downloads views show of a stored file: its URL, extension (from the stored key, else the name) and kind.
     *
     * @return array{url: string, extension: string, kind: string}
     */
    public static function downloadFacts(Download $row): array
    {
        $extension = ($fromKey = Files::extensionOf($row->file_path)) !== '' ? $fromKey : Files::extensionOf($row->file_name);

        return ['url' => Storage::storedUrl($row->file_path), 'extension' => $extension, 'kind' => Files::fileKind($extension)];
    }

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

    /**
     * A Download row of the admin list, as the Downloads manager renders it. The row's `department` relation must be loaded.
     *
     * @return array{id: int, fileName: string, url: string, size: int, extension: string, kind: string, directory: string, tags: list<string>, departmentId: ?int, departmentName: ?string, createdAt: string}
     */
    public static function toAdminDownload(Download $row): array
    {
        ['url' => $url, 'extension' => $extension, 'kind' => $kind] = self::downloadFacts($row);

        return [
            'id' => $row->id,
            'fileName' => $row->file_name,
            'url' => $url,
            'size' => $row->file_size,
            'extension' => $extension,
            'kind' => $kind,
            'directory' => self::directoryOf($row->directory),
            'tags' => self::parseTags($row->tags),
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
            $name = self::directoryOf($group->directory);
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
            ['url' => $url, 'extension' => $extension, 'kind' => $kind] = self::downloadFacts($row);

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
                'tags' => self::parseTags($row->tags),
                'directory' => self::directoryOf($row->directory),
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
        $names = collect([self::DEFAULT_DIRECTORY])->merge($custom)->merge($used)->unique()->values()->all();

        $collator = class_exists(\Collator::class) ? new \Collator('en') : null;
        usort($names, fn (string $a, string $b) => $collator ? $collator->compare($a, $b) : strcasecmp($a, $b));

        return $names;
    }

    /** @return list<string> */
    private static function splitTags(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $text)), fn (string $t) => $t !== ''));
    }

    private static function tagText(mixed $tag): string
    {
        return match (true) {
            is_string($tag) => $tag,
            is_bool($tag) => $tag ? 'true' : 'false',
            $tag === null => 'null',
            is_scalar($tag) => (string) $tag,
            default => json_encode($tag),
        };
    }
}
