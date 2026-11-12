<?php

namespace App\Storage;

use App\Support\RequestError;
use Illuminate\Http\Request;

/** The JSON body both direct-upload endpoints take (`/api/admin/uploads/direct` for Downloads, `/api/admin/uploads/video` for videos). */
final class UploadRequest
{
    /**
     * Reads `{ fileName, contentType, size }`; throws `RequestError` (400) when the shape is wrong. Types and limits
     * are the caller's. `contentType` is the browser's declared type, `''` when missing.
     *
     * @return array{fileName: string, contentType: string, size: int}
     */
    public static function readUploadRequest(Request $request): array
    {
        $raw = $request->getContent();
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw RequestError::badRequest('Expected a JSON body.');
        }
        $body = is_array($body) ? $body : [];
        $fileName = $body['fileName'] ?? null;
        $size = $body['size'] ?? null;
        if (! is_string($fileName) || trim($fileName) === '' || mb_strlen($fileName) > 255) {
            throw RequestError::badRequest('A file name is required.');
        }
        if (is_float($size) && floor($size) === $size && abs($size) < PHP_INT_MAX) {
            $size = (int) $size;
        }
        if (! is_int($size) || $size <= 0) {
            throw RequestError::badRequest('Choose a file to upload.');
        }
        $contentType = $body['contentType'] ?? null;

        return ['fileName' => $fileName, 'contentType' => is_string($contentType) ? $contentType : '', 'size' => $size];
    }
}
