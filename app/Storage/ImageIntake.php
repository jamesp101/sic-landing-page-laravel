<?php

namespace App\Storage;

use App\Auth\RateLimit;
use App\Support\RequestError;
use Illuminate\Support\Facades\Validator;

/**
 * Image intake for uploads that are not yet attached to a record: inline content images and slide Layer images.
 * One seam for the rate limit, the WebP conversion and storage.
 */
final class ImageIntake
{
    /**
     * Intake kinds: the image target (also the browser's compression box) and where the file is stored. Layer
     * images stay under `carousels/` so the unused-layer-image lookup finds them.
     */
    public const IMAGE_INTAKES = [
        'content' => ['prefix' => 'posts/inline', 'target' => 'content'],
        'layer' => ['prefix' => 'carousels', 'target' => 'layer'],
    ];

    public static function isImageIntakeKind(mixed $value): bool
    {
        return is_string($value) && isset(self::IMAGE_INTAKES[$value]);
    }

    /**
     * Accepts an uploaded image from `$userId`: rate limit first (conversion is the expensive part), then WebP
     * conversion, then storage. Throws RequestError for a rejected image or an exhausted quota.
     *
     * @return array{key: string, url: string}
     */
    public static function acceptImage(int $userId, string $kind, mixed $raw): array
    {
        ['prefix' => $prefix, 'target' => $target] = self::IMAGE_INTAKES[$kind];
        RateLimit::assertUploadQuota($userId);
        $validator = Validator::make(['image' => $raw], [
            'image' => ['required', ...ImageFile::imageFile(Storage::limit('image'), $target)],
        ], ['image.required' => ImageFile::MISSING]);
        if ($validator->fails()) {
            throw RequestError::badRequest($validator->errors()->first('image') ?: 'Invalid image.');
        }
        $key = Storage::put($prefix, ImageFile::storedImage(ImageFile::upload($raw, $target)));

        return ['key' => $key, 'url' => Storage::storedUrl($key)];
    }
}
