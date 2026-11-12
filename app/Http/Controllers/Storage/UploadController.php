<?php

namespace App\Http\Controllers\Storage;

use App\Auth\RateLimit;
use App\Http\Controllers\Controller;
use App\Storage\DirectUpload;
use App\Storage\DownloadIntake;
use App\Storage\FileTypes;
use App\Storage\ImageIntake;
use App\Storage\PendingUploads;
use App\Storage\Storage;
use App\Storage\UploadRequest;
use App\Storage\VideoIntake;
use App\Support\Authz;
use App\Support\RequestError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Browser uploads that are not attached to a record yet (`/api/admin/uploads/*`); every route needs a signed-in user. */
class UploadController extends Controller
{
    /**
     * Image upload for the content editor and the slide designer's Layer images. Multipart form with `image` and
     * `kind` (`content` | `layer`, default `content`). Returns `{ url }`.
     */
    public function image(Request $request): JsonResponse
    {
        $user = Authz::requireUser($request->user());
        $kind = $request->input('kind', 'content');
        if (! ImageIntake::isImageIntakeKind($kind)) {
            throw RequestError::badRequest('Unknown image kind.');
        }

        return response()->json(['url' => ImageIntake::acceptImage($user->id, $kind, $request->file('image'))['url']]);
    }

    /**
     * Browser-direct uploads to the S3 bucket for files that would exceed Vercel's ~4.5 MB request limit. A
     * signed-in user posts `{ fileName, contentType, size }`; the response is a server-generated
     * `downloads/<random>.<ext>` key and a 10-minute presigned PUT URL that only accepts exactly that content type
     * and size. The key is recorded as issued to this user: only they can attach it with `downloads.create` (which
     * re-checks the stored size); an abandoned object is reclaimed by the orphan cleanup.
     */
    public function direct(Request $request): JsonResponse
    {
        $user = Authz::requireUser($request->user());
        if (! Storage::directUploads()) {
            throw RequestError::notFound('Direct uploads are not enabled.');
        }

        $upload = UploadRequest::readUploadRequest($request);
        // Downloads keep their own allowlist: every file type except videos, which only the video upload accepts.
        if (! FileTypes::isAllowedContentType($upload['contentType']) || FileTypes::isVideoUpload($upload['fileName'], $upload['contentType'])) {
            throw RequestError::unprocessable('This file type is not allowed.');
        }
        if ($upload['size'] > Storage::limit('download')) {
            throw RequestError::unprocessable(DownloadIntake::DOWNLOAD_TOO_LARGE);
        }
        RateLimit::assertUploadQuota($user->id);

        $key = DirectUpload::newDirectUploadKey($upload['fileName'], $upload['contentType']);
        PendingUploads::registerPendingUpload($key, $user->id);

        return response()->json(['key' => $key, 'url' => Storage::presignUpload($key, $upload['contentType'], $upload['size'])]);
    }

    /**
     * Step 1 of a video upload. A signed-in user posts `{ fileName, contentType, size }` (MP4/WebM, up to 100 MB);
     * the response is a server-generated `videos/<random>.<ext>` key (recorded in `pending_uploads` for this user)
     * and where to PUT the bytes: a 10-minute presigned bucket URL on `s3`, `videoBytes` on `local`. `contentType`
     * is the type to send with the PUT. Step 2 is `videoComplete`.
     */
    public function video(Request $request): JsonResponse
    {
        $user = Authz::requireUser($request->user());
        $upload = UploadRequest::readUploadRequest($request);
        $checked = VideoIntake::validateVideoUpload($upload);
        if (! $checked['ok']) {
            throw RequestError::unprocessable($checked['message']);
        }
        RateLimit::assertUploadQuota($user->id);

        $key = DirectUpload::newDirectUploadKey($upload['fileName'], $checked['contentType'], DirectUpload::VIDEO_UPLOAD_PREFIX);
        PendingUploads::registerPendingUpload($key, $user->id);
        $url = Storage::directUploads()
            ? Storage::presignUpload($key, $checked['contentType'], $upload['size'])
            : '/api/admin/uploads/video?'.http_build_query(['key' => $key, 'size' => (string) $upload['size']], '', '&', PHP_QUERY_RFC1738);

        return response()->json(['key' => $key, 'url' => $url, 'contentType' => $checked['contentType']]);
    }

    /**
     * Local driver only: receives the video bytes for a key issued by `video` to the same user, streamed to disk
     * without buffering and stopped once they exceed the announced size. A cross-site page cannot call this: a PUT
     * with credentials always needs a CORS preflight, which this app never answers.
     */
    public function videoBytes(Request $request): Response
    {
        $user = Authz::requireUser($request->user());
        if (Storage::directUploads()) {
            throw RequestError::notFound('Videos are uploaded straight to the bucket.');
        }
        $size = (string) $request->query('size', '');
        VideoIntake::receive($user->id, (string) $request->query('key', ''), ctype_digit($size) ? (int) $size : 0, $request->getContent(true));

        return response()->noContent();
    }

    /**
     * Step 2 of a video upload: `{ key }` from `video`. Checks the key was issued to this user and the object
     * exists and fits, claims the pending upload (so expiry never deletes it) and returns the app route URL
     * (`/storage/<key>`, whatever `S3_PUBLIC_URL` is) to store in content.
     */
    public function videoComplete(Request $request): JsonResponse
    {
        $user = Authz::requireUser($request->user());
        $body = json_decode($request->getContent(), true);
        if (! is_array($body) || ! is_string($body['key'] ?? null)) {
            throw RequestError::badRequest('Expected a JSON body with a key.');
        }
        $uploaded = VideoIntake::complete($user->id, $body['key']);

        return response()->json(['url' => Storage::storageRouteUrl($uploaded['key'])]);
    }
}
