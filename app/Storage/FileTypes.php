<?php

namespace App\Storage;

/**
 * Extensions uploads may be stored under, with the only content type each is ever stored and served as. Markup
 * that browsers execute (HTML, SVG, XML, JS) is deliberately absent: such files keep no extension and are stored
 * as `application/octet-stream`.
 */
final class FileTypes
{
    public const TYPES = [
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.png' => 'image/png',
        '.webp' => 'image/webp',
        '.gif' => 'image/gif',
        '.avif' => 'image/avif',
        '.pdf' => 'application/pdf',
        '.txt' => 'text/plain',
        '.csv' => 'text/csv',
        '.doc' => 'application/msword',
        '.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.xls' => 'application/vnd.ms-excel',
        '.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        '.ppt' => 'application/vnd.ms-powerpoint',
        '.pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        '.zip' => 'application/zip',
        ...VideoTypes::TYPES,
    ];

    /** @return array<string, string> content type → extension (the first extension listed wins, as in the TS reverse lookup) */
    private static function extensionByType(): array
    {
        $map = [];
        foreach (self::TYPES as $ext => $type) {
            $map[$type] ??= $ext;
        }
        $map['application/x-zip-compressed'] = '.zip';

        return $map;
    }

    /** The extension of a file name with its dot, lower-cased (`''` when it has none). */
    public static function extensionOf(string $fileName): string
    {
        $base = basename(str_replace('\\', '/', $fileName));
        $dot = strrpos($base, '.');

        return $dot === false || $dot === 0 ? '' : strtolower(substr($base, $dot));
    }

    /** True for content types a browser may declare for an upload (each maps to an allowed extension). */
    public static function isAllowedContentType(string $type): bool
    {
        return isset(self::extensionByType()[$type]);
    }

    /**
     * Extension and content type an upload is stored under: a known extension from the file name, else the
     * extension implied by a known declared type, else none. The client-declared type is never stored as such, so a
     * stored object can only be served as one of the `TYPES` types.
     *
     * @return array{ext: string, contentType: string}
     */
    public static function storedType(string $fileName, string $declaredType): array
    {
        $nameExt = self::extensionOf($fileName);
        $byType = self::extensionByType();
        $ext = isset(self::TYPES[$nameExt]) ? $nameExt : ($byType[$declaredType] ?? '');

        return ['ext' => $ext, 'contentType' => $ext !== '' ? self::TYPES[$ext] : 'application/octet-stream'];
    }

    /**
     * True when an upload would be stored as a video (by file name, else by declared type). Downloads and images
     * refuse these: a video is only accepted by the video upload flow.
     */
    public static function isVideoUpload(string $fileName, string $declaredType): bool
    {
        return isset(VideoTypes::TYPES[self::storedType($fileName, $declaredType)['ext']]);
    }
}
