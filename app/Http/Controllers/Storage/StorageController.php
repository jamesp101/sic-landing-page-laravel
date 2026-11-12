<?php

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Storage\FileTypes;
use App\Storage\Range;
use App\Storage\RangeNotSatisfiableException;
use App\Storage\Storage;
use App\Storage\StoredObject;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves uploaded files (and legacy `/storage/*` URLs) from the configured driver. With S3_PUBLIC_URL set, inline
 * requests for allowlisted extensions redirect to the object URL; `?download=` and every other key are streamed
 * here so these headers apply. A single `Range: bytes=…` request gets a 206 slice (video seeking), an unsatisfiable
 * one a 416.
 */
class StorageController extends Controller
{
    /** Types a browser may display inline (scripts stay blocked by the CSP below); everything else is an attachment. */
    private const INLINE = '#^(image/(jpeg|png|webp|gif|avif|svg\+xml)|video/(mp4|webm)|application/pdf|text/(plain|csv))$#D';

    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    private const FALLBACK = 'public, max-age=300';

    private const CHUNK = 256 * 1024;

    public function __invoke(Request $request, string $path = ''): Response
    {
        // A missing image variant is answered with its original (and a short cache time, so the variant is picked up once it exists).
        $key = Storage::servedKey($path);
        $fallback = $key !== $path;
        $name = $request->query->has('download') ? (string) $request->query->get('download') : null;
        $ext = FileTypes::extensionOf($key);

        $base = Storage::directBaseUrl();
        if ($base !== null && $name === null && isset(FileTypes::TYPES[$ext])) {
            try {
                $target = Storage::publicUrl($key);
            } catch (InvalidArgumentException) {
                return $this->notFound();
            }
            if ($target !== null) {
                // The variant redirect never changes (keys are unique); the fallback is short-lived so the variant is picked up once it exists.
                return redirect($target, $fallback ? 302 : 301)
                    ->header('Cache-Control', $fallback ? self::FALLBACK : self::IMMUTABLE);
            }
        }

        try {
            $file = Storage::read($key, Range::parseRangeHeader($request->headers->get('Range')));
        } catch (RangeNotSatisfiableException $error) {
            return response('', 416, ['Content-Range' => "bytes */{$error->size}", 'Accept-Ranges' => 'bytes']);
        }
        if ($file === null) {
            return $this->notFound();
        }

        // The type follows the extension only (never the stored object's metadata), so an upload can never be
        // served as HTML. Legacy `.svg` files render as images under the CSP below.
        $type = $ext === '.svg' ? 'image/svg+xml' : (FileTypes::TYPES[$ext] ?? 'application/octet-stream');
        $headers = [
            'Content-Type' => str_starts_with($type, 'text/') ? "{$type}; charset=utf-8" : $type,
            'Cache-Control' => $fallback ? self::FALLBACK : self::IMMUTABLE,
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $etag = $file->mtime !== null ? sprintf('W/"%x-%x"', $file->size, $file->mtime * 1000) : null;
        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }
        if ($file->mtime !== null) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $file->mtime).' GMT';
        }
        // Conditional requests are answered before Range, and without sending the body.
        if ($this->isNotModified($request, $etag, $file->mtime)) {
            $file->close();

            return response('', 304, $headers);
        }
        // PDFs are exempt from the sandbox: Chrome refuses to render a PDF in a sandboxed document.
        if ($type !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }
        $length = $file->length();
        if ($length > 0) {
            $headers['Content-Length'] = (string) $length;
        }
        if ($file->range !== null) {
            $headers['Content-Range'] = "bytes {$file->range['start']}-{$file->range['end']}/{$file->size}";
        }
        if ($name !== null || preg_match(self::INLINE, $type) !== 1) {
            $download = preg_replace('/["\r\n]/', '', $name !== null && $name !== '' ? $name : basename($key));
            $headers['Content-Disposition'] = "attachment; filename=\"{$download}\"";
        }

        return new StreamedResponse(fn () => $this->send($file, $length), $file->range !== null ? 206 : 200, $headers);
    }

    private function notFound(): Response
    {
        // A short cache time keeps a crawler or a broken link from reaching the store on every request.
        return response('Not found', 404, ['Cache-Control' => 'public, max-age=60', 'Accept-Ranges' => 'bytes']);
    }

    private function send(StoredObject $file, int $length): void
    {
        try {
            $left = $length;
            while ($left > 0 && ! feof($file->body)) {
                $chunk = fread($file->body, min(self::CHUNK, $left));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $left -= strlen($chunk);
                echo $chunk;
                flush();
            }
        } finally {
            $file->close();
        }
    }

    /** True when the request's validators say the client's copy is current (304). `If-None-Match` wins over `If-Modified-Since`. */
    private function isNotModified(Request $request, ?string $etag, ?int $mtime): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null) {
            if ($etag === null) {
                return false;
            }
            $strip = fn (string $tag) => preg_replace('#^W/#', '', trim($tag));
            $wanted = $strip($etag);
            foreach (explode(',', $ifNoneMatch) as $tag) {
                if (trim($tag) === '*' || $strip($tag) === $wanted) {
                    return true;
                }
            }

            return false;
        }
        $since = strtotime((string) $request->headers->get('If-Modified-Since'));

        return $mtime !== null && $since !== false && $mtime <= $since;
    }
}
