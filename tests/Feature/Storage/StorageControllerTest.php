<?php

namespace Tests\Feature\Storage;

use App\Storage\Storage;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class StorageControllerTest extends TestCase
{
    use UsesUploadsDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function bytes(): string
    {
        return implode('', array_map(fn (int $i) => chr($i % 251), range(0, 999)));
    }

    public function test_a_stored_file_is_served_with_the_type_of_its_extension_and_immutable_cache_headers(): void
    {
        $key = Storage::put('posts', $this->upload($this->imageBytes(10, 10, 'webp'), 'a.webp', 'text/html'));
        $response = $this->get("/storage/{$key}");
        $response->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame($this->imageBytes(10, 10, 'webp'), $response->streamedContent());
        $this->assertNull($response->headers->get('Content-Disposition'), 'images are shown inline');
    }

    public function test_range_requests_get_a_206_slice_and_an_unsatisfiable_one_a_416(): void
    {
        Storage::putBytes('videos/a.mp4', $this->bytes(), 'video/mp4');

        $slice = $this->withHeaders(['Range' => 'bytes=10-19'])->get('/storage/videos/a.mp4');
        $slice->assertStatus(206);
        $this->assertSame('bytes 10-19/1000', $slice->headers->get('Content-Range'));
        $this->assertSame('10', $slice->headers->get('Content-Length'));
        $this->assertSame('bytes', $slice->headers->get('Accept-Ranges'));
        $this->assertSame(substr($this->bytes(), 10, 10), $slice->streamedContent());

        $tail = $this->withHeaders(['Range' => 'bytes=-5'])->get('/storage/videos/a.mp4');
        $tail->assertStatus(206);
        $this->assertSame('bytes 995-999/1000', $tail->headers->get('Content-Range'));
        $this->assertSame(substr($this->bytes(), 995), $tail->streamedContent());

        $past = $this->withHeaders(['Range' => 'bytes=1000-'])->get('/storage/videos/a.mp4');
        $past->assertStatus(416);
        $this->assertSame('bytes */1000', $past->headers->get('Content-Range'));
        $this->assertSame('bytes', $past->headers->get('Accept-Ranges'));

        $whole = $this->withHeaders(['Range' => 'bytes=5-2'])->get('/storage/videos/a.mp4');
        $whole->assertOk();
        $this->assertSame('video/mp4', $whole->headers->get('Content-Type'));
        $this->assertSame('bytes', $whole->headers->get('Accept-Ranges'));
        $this->assertSame(1000, strlen($whole->streamedContent()));
    }

    public function test_a_missing_key_answers_404_with_a_short_cache_time(): void
    {
        $response = $this->get('/storage/posts/none.webp');
        $response->assertNotFound();
        $this->assertSame('max-age=60, public', $response->headers->get('Cache-Control'));
        $this->get('/storage/posts/..%2F..%2Fetc/passwd')->assertNotFound();
    }

    public function test_a_missing_variant_serves_the_original_but_only_for_known_variant_kinds(): void
    {
        Storage::putBytes('posts/vo.webp', 'ORIGINAL', 'image/webp');
        Storage::putBytes('downloads/vd.webp', 'DOWNLOAD', 'image/webp');

        $fallback = $this->get('/storage/posts/vo.w640.webp');
        $fallback->assertOk();
        $this->assertSame('ORIGINAL', $fallback->streamedContent());
        $this->assertSame('max-age=300, public', $fallback->headers->get('Cache-Control'));

        $this->get('/storage/downloads/vd.w640.webp')->assertNotFound();
        $this->get('/storage/posts/vo.w960.webp')->assertNotFound();

        Storage::putBytes('posts/vo.w640.webp', 'VARIANT', 'image/webp');
        $variant = $this->get('/storage/posts/vo.w640.webp');
        $this->assertSame('VARIANT', $variant->streamedContent());
        $this->assertStringContainsString('immutable', $variant->headers->get('Cache-Control'));
    }

    public function test_other_types_are_octet_stream_attachments_and_html_is_never_served_as_html(): void
    {
        $key = Storage::put('downloads', $this->upload('<script>alert(1)</script>', 'page.html', 'text/html'));
        $response = $this->get("/storage/{$key}");
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="', $response->headers->get('Content-Disposition'));

        $text = Storage::put('downloads', $this->upload('plain', 'notes.txt', 'text/plain'));
        $this->assertSame('text/plain; charset=utf-8', $this->get("/storage/{$text}")->headers->get('Content-Type'));
        $this->assertNull($this->get("/storage/{$text}")->headers->get('Content-Disposition'));

        $zip = Storage::put('downloads', $this->upload('PK', 'a.zip', 'application/zip'));
        $this->assertStringStartsWith('attachment;', $this->get("/storage/{$zip}")->headers->get('Content-Disposition'));
    }

    public function test_pdfs_are_exempt_from_the_sandbox(): void
    {
        $key = Storage::put('downloads', $this->upload('%PDF-1.4', 'form.pdf', 'application/pdf'));
        $response = $this->get("/storage/{$key}");
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Disposition'));
    }

    public function test_the_download_parameter_forces_an_attachment_with_a_safe_file_name(): void
    {
        $key = Storage::put('posts', $this->upload($this->imageBytes(4, 4, 'png'), 'a.png', 'image/png'));
        $response = $this->get("/storage/{$key}?download=".rawurlencode("Fo\"rm\r\n 1.png"));
        $this->assertSame('attachment; filename="Form 1.png"', $response->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="'.basename($key).'"', $this->get("/storage/{$key}?download=")->headers->get('Content-Disposition'));
    }

    public function test_legacy_storage_prefixed_keys_still_resolve(): void
    {
        Storage::putBytes('posts/a.png', 'PNG', 'image/png');
        $this->get('/storage/storage/posts/a.png')->assertOk();
    }

    public function test_conditional_requests_get_a_304_without_a_body(): void
    {
        Storage::putBytes('posts/a.png', 'PNG', 'image/png');
        $first = $this->get('/storage/posts/a.png');
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])->get('/storage/posts/a.png')->assertStatus(304);
        $this->withHeaders(['If-None-Match' => '"other"'])->get('/storage/posts/a.png')->assertOk();
        $this->flushHeaders();
        $this->withHeaders(['If-Modified-Since' => $first->headers->get('Last-Modified')])->get('/storage/posts/a.png')->assertStatus(304);
    }

    public function test_with_a_public_base_inline_requests_redirect_to_the_bucket_but_variants_downloads_and_unknown_types_stay_on_the_app(): void
    {
        $this->useFakeBucket('https://cdn.example.org/sic');
        Storage::putBytes('posts/a.webp', 'ORIGINAL', 'image/webp');

        $redirect = $this->get('/storage/posts/a.webp');
        $redirect->assertStatus(301);
        $redirect->assertRedirect('https://cdn.example.org/sic/posts/a.webp');
        $this->assertStringContainsString('immutable', $redirect->headers->get('Cache-Control'));

        // A missing variant is answered by its original, redirected for a short time only.
        $fallback = $this->get('/storage/posts/a.w640.webp');
        $fallback->assertStatus(302);
        $fallback->assertRedirect('https://cdn.example.org/sic/posts/a.webp');
        $this->assertSame('max-age=300, public', $fallback->headers->get('Cache-Control'));

        // `?download=` is streamed here so the attachment headers apply.
        $download = $this->get('/storage/posts/a.webp?download=a.webp');
        $download->assertOk();
        $this->assertSame('ORIGINAL', $download->streamedContent());

        // An unknown extension is never redirected to the bucket.
        Storage::putBytes('downloads/blob', 'x', 'application/octet-stream');
        $this->get('/storage/downloads/blob')->assertOk();
    }

    public function test_the_bucket_variant_is_served_through_the_app_when_it_exists(): void
    {
        $this->useFakeBucket('https://cdn.example.org/sic');
        Storage::putBytes('posts/a.webp', 'ORIGINAL', 'image/webp');
        Storage::putBytes('posts/a.w640.webp', 'VARIANT', 'image/webp');
        $this->get('/storage/posts/a.w640.webp')->assertStatus(301);
        $this->assertSame('/storage/posts/a.w640.webp', Storage::variantUrl('posts/a.webp', 640));
    }
}
