<?php

namespace App\Storage;

/** Uploaded-video types and limit. */
final class VideoTypes
{
    /** Video extensions and the only content type each is stored and served as. */
    public const TYPES = [
        '.mp4' => 'video/mp4',
        '.webm' => 'video/webm',
    ];

    /** Largest uploaded video, in bytes. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** Value for `<input accept>` on a video picker. */
    public const ACCEPT = 'video/mp4,video/webm,.mp4,.webm';

    /** The video content type of a file: its extension decides, else its declared type; null when it is not MP4/WebM. */
    public static function videoContentType(string $fileName, string $declaredType): ?string
    {
        $dot = strrpos($fileName, '.');
        $ext = $dot === false ? '' : strtolower(substr($fileName, $dot));
        if (isset(self::TYPES[$ext])) {
            return self::TYPES[$ext];
        }

        return in_array($declaredType, self::TYPES, true) ? $declaredType : null;
    }
}
