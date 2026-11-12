<?php

declare(strict_types=1);

namespace App\Support;

final class Files
{
    private const KINDS = [
        'pdf' => 'pdf',
        'doc' => 'doc', 'docx' => 'doc', 'odt' => 'doc', 'rtf' => 'doc', 'txt' => 'doc', 'ppt' => 'doc', 'pptx' => 'doc',
        'xls' => 'sheet', 'xlsx' => 'sheet', 'csv' => 'sheet', 'ods' => 'sheet',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image', 'svg' => 'image', 'avif' => 'image',
        'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive', 'gz' => 'archive',
    ];

    /** Lower-case extension without the dot; '' when the name has none. */
    public static function extensionOf(string $name): string
    {
        $parts = preg_split('/[\\\\\/]/', $name);
        $base = (string) end($parts);
        $dot = strrpos($base, '.');

        return $dot !== false && $dot > 0 && $dot < strlen($base) - 1 ? mb_strtolower(substr($base, $dot + 1)) : '';
    }

    /** Accepts an extension with or without the leading dot. Returns `pdf|doc|sheet|image|archive|other`. */
    public static function fileKind(string $extension): string
    {
        $key = mb_strtolower(preg_replace('/^\./', '', $extension));

        return self::KINDS[$key] ?? 'other';
    }
}
