<?php

namespace App\Downloads;

use App\Models\Download;
use App\Models\DownloadDirectory;
use App\Storage\Storage;
use App\Support\Files;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Download rules shared by the public `/downloads` page and the admin Downloads list: how a Download is stored
 * (Tags as a JSON array in one column, a missing Directory meaning General) and what is derived from the stored
 * file (URL, extension, File kind). Holds no view model and no authorization.
 */
final class DownloadRules
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
     * What both Downloads views show of a stored file: its URL, extension (from the stored key, else the name) and File kind.
     *
     * @return array{url: string, extension: string, kind: string}
     */
    public static function downloadFacts(Download $row): array
    {
        $extension = ($fromKey = Files::extensionOf($row->file_path)) !== '' ? $fromKey : Files::extensionOf($row->file_name);

        return ['url' => Storage::storedUrl($row->file_path), 'extension' => $extension, 'kind' => Files::fileKind($extension)];
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
