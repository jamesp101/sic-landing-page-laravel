<?php

namespace Tests\Unit\Storage;

use App\Storage\RangeNotSatisfiableException;
use App\Storage\Storage;
use App\Storage\UploadTooLargeException;
use App\Storage\VideoTypes;
use InvalidArgumentException;
use Tests\TestCase;

class StorageTest extends TestCase
{
    use UsesUploadsDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    public function test_it_normalises_legacy_storage_paths_to_bare_keys_and_app_urls(): void
    {
        $this->assertSame('local', Storage::driverName());
        $this->assertSame('posts/a.jpg', Storage::toKey('/storage/posts/a.jpg'));
        $this->assertSame('/storage/posts/a.jpg', Storage::publicUrl('/storage/posts/a.jpg'));
        $this->assertSame('/storage/downloads/b.pdf', Storage::publicUrl('downloads/b.pdf'));
        $this->assertSame('/sic-seal.png', Storage::publicUrl('/sic-seal.png'));
        $this->assertSame('https://example.org/x.png', Storage::publicUrl('https://example.org/x.png'));
        $this->assertNull(Storage::publicUrl(null));
        $this->assertSame('/storage/downloads/b.pdf?download=Form%201.pdf', Storage::downloadUrl('downloads/b.pdf', 'Form 1.pdf'));
    }

    public function test_download_url_encodes_like_encode_uri_component(): void
    {
        $this->assertSame("/storage/downloads/b.pdf?download=It's%20(a)%20b!*%26%C3%A9.pdf", Storage::downloadUrl('downloads/b.pdf', "It's (a) b!*&é.pdf"));
    }

    public function test_it_rejects_path_traversal_for_every_operation(): void
    {
        foreach (['../etc/passwd', 'posts/../../x'] as $key) {
            try {
                Storage::toKey($key);
                $this->fail("{$key} must be rejected");
            } catch (InvalidArgumentException $error) {
                $this->assertSame('Invalid storage key', $error->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->assertNull(Storage::read('posts/../../etc/passwd'));
        } finally {
            Storage::remove('../outside.txt');
        }
    }

    public function test_it_round_trips_put_read_remove_under_the_prefix(): void
    {
        $key = Storage::put('posts/inline', $this->upload('hello', 'Photo.JPG', 'image/jpeg'));
        $this->assertMatchesRegularExpression('#^posts/inline/[0-9a-f]{32}\.jpg$#', $key);
        $file = Storage::read($key);
        $this->assertSame(5, $file->size);
        $this->assertSame('hello', $file->contents());
        Storage::remove("/storage/{$key}");
        $this->assertNull(Storage::read($key));
    }

    public function test_the_extension_decides_the_stored_type_and_unknown_files_keep_none(): void
    {
        $this->assertStringEndsWith('.pdf', Storage::put('downloads', $this->upload('x', 'Form.PDF', 'text/html')));
        $html = Storage::put('downloads', $this->upload('<script>', 'page.html', 'text/html'));
        $this->assertDoesNotMatchRegularExpression('#\.[a-z]+$#', $html);
        $this->assertStringEndsWith('.zip', Storage::put('downloads', $this->upload('x', 'noext', 'application/zip')));
    }

    public function test_it_ignores_removal_of_missing_objects_and_external_urls(): void
    {
        Storage::remove('posts/missing.png');
        Storage::remove('https://example.org/x.png');
        Storage::remove(null);
        $this->assertNull(Storage::read('posts/missing.png'));
    }

    public function test_a_failing_write_leaves_nothing_behind(): void
    {
        $big = str_repeat("\7", 3 * 1024 * 1024);
        Storage::putBytes('downloads/big.bin', $big, 'application/octet-stream');
        $this->assertSame(strlen($big), Storage::read('downloads/big.bin')->size);

        $path = Storage::resolve('downloads/blocked.bin');
        mkdir($path); // a directory in the way: the rename of the partial file must fail
        try {
            Storage::putBytes('downloads/blocked.bin', 'x', 'application/octet-stream');
            $this->fail('the write must fail');
        } catch (\Throwable) {
            // expected
        }
        $names = array_values(array_diff(scandir(dirname($path)), ['.', '..']));
        sort($names);
        $this->assertSame(['big.bin', 'blocked.bin'], $names);
    }

    public function test_it_serves_the_original_of_a_missing_variant_only_for_kinds_that_have_variants(): void
    {
        foreach (['posts/vo.webp', 'members/vm.jpg', 'members/vm.w128.webp', 'downloads/vd.webp'] as $key) {
            Storage::putBytes($key, 'x', 'image/webp');
        }
        $this->assertSame('posts/vo.webp', Storage::servedKey('posts/vo.w640.webp'));
        $this->assertSame('members/vm.w128.webp', Storage::servedKey('members/vm.w128.webp'));
        $this->assertSame('posts/vo.webp', Storage::servedKey('posts/vo.webp'));
        // Not a variant of its kind: no lookup at all, the route answers 404.
        foreach (['downloads/vd.w640.webp', 'posts/vo.w960.webp', 'posts/inline/vo.w640.webp', 'posts/none.w640.webp'] as $key) {
            $this->assertSame($key, Storage::servedKey($key));
        }
    }

    public function test_stored_url_is_the_public_url_of_a_non_empty_key(): void
    {
        $this->assertSame('/storage/carousels/a.webp', Storage::storedUrl('carousels/a.webp'));
        $this->assertSame('https://example.org/x.png', Storage::storedUrl('https://example.org/x.png'));
        $this->expectException(InvalidArgumentException::class);
        Storage::storedUrl('');
    }

    public function test_stored_url_follows_the_public_base_on_s3(): void
    {
        $this->useFakeBucket('https://cdn.example.org');
        $this->assertSame('https://cdn.example.org/carousels/a.webp', Storage::storedUrl('carousels/a.webp'));
    }

    public function test_it_rejects_unknown_drivers_and_a_missing_bucket(): void
    {
        config(['sic.storage.driver' => 'ftp']);
        try {
            Storage::driverName();
            $this->fail('ftp must be rejected');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('Unsupported STORAGE_DRIVER "ftp"', $error->getMessage());
        }
        config(['sic.storage.driver' => 's3', 'filesystems.disks.uploads-s3.bucket' => null]);
        $this->expectExceptionMessage('S3_BUCKET is required');
        Storage::disk();
    }

    public function test_s3_objects_are_served_through_storage_unless_a_public_base_is_set(): void
    {
        config(['sic.storage.driver' => 'S3', 'filesystems.disks.uploads-s3.bucket' => 'sic']);
        $this->assertSame('s3', Storage::driverName());
        $this->assertNull(Storage::directBaseUrl());
        $this->assertSame('/storage/posts/a.jpg', Storage::publicUrl('posts/a.jpg'));

        config(['sic.storage.public_url' => 'https://cdn.example.org/sic']);
        $this->assertSame('https://cdn.example.org/sic/posts/a.jpg', Storage::publicUrl('/storage/posts/a.jpg'));
        // Downloads stay on the app route so the attachment filename is applied.
        $this->assertSame('/storage/downloads/b.pdf?download=b.pdf', Storage::downloadUrl('downloads/b.pdf', 'b.pdf'));
    }

    public function test_the_public_base_is_ignored_for_the_local_driver(): void
    {
        config(['sic.storage.public_url' => 'https://cdn.example.org']);
        $this->assertSame('/storage/posts/a.jpg', Storage::publicUrl('posts/a.jpg'));
    }

    public function test_key_from_public_url_reads_back_what_public_url_produced_and_rejects_everything_else(): void
    {
        $this->assertSame('carousels/a.webp', Storage::keyFromPublicUrl('/storage/carousels/a.webp'));
        foreach (['https://cdn.example.org/carousels/a.webp', '/other/carousels/a.webp', '/storage/../secret', '/storage/carousels/../../secret', '/storage/'] as $url) {
            $this->assertNull(Storage::keyFromPublicUrl($url), $url);
        }

        $this->useFakeBucket('https://cdn.example.org/sic/');
        $this->assertSame('carousels/a.webp', Storage::keyFromPublicUrl('https://cdn.example.org/sic/carousels/a.webp'));
        $this->assertSame('carousels/a.webp', Storage::keyFromPublicUrl('/storage/carousels/a.webp'));
        $this->assertNull(Storage::keyFromPublicUrl('https://evil.example.org/sic/carousels/a.webp'));
        $this->assertNull(Storage::keyFromPublicUrl('https://cdn.example.org/sic/../x'));
    }

    public function test_direct_uploads_exist_only_on_s3_which_keeps_multipart_images_under_the_vercel_limit(): void
    {
        $this->useS3Config();
        $this->assertTrue(Storage::directUploads());
        $this->assertSame(4 * Storage::MB, Storage::limit('image'));

        $this->useLocalUploads();
        $this->assertFalse(Storage::directUploads());
        $this->assertSame(5 * Storage::MB, Storage::limit('image'));
        $this->expectExceptionMessage('Direct uploads require STORAGE_DRIVER=s3');
        Storage::presignUpload('downloads/a.pdf', 'application/pdf', 10);
    }

    public function test_it_presigns_a_put_under_the_prefix_that_pins_content_type_and_length(): void
    {
        $this->useS3Config();
        $url = parse_url(Storage::presignUpload('downloads/a.pdf', 'application/pdf', 1234));
        parse_str($url['query'], $query);
        $this->assertStringEndsWith('/site/downloads/a.pdf', $url['path']);
        $signed = explode(';', $query['X-Amz-SignedHeaders']);
        $this->assertContains('content-type', $signed);
        $this->assertContains('content-length', $signed);
        $this->assertContains('host', $signed);
        $this->assertSame('600', $query['X-Amz-Expires']);
        $this->assertSame([], array_filter(array_keys($query), fn ($name) => stripos($name, 'checksum') !== false));
    }

    // ---- storage-video.test.ts -------------------------------------------------------------------------------

    public function test_video_key_from_url_returns_the_bare_key_of_our_video_urls_and_nothing_else(): void
    {
        $this->assertSame('videos/a.mp4', Storage::videoKeyFromUrl('/storage/videos/a.mp4'));
        $this->assertSame('videos/a.webm', Storage::videoKeyFromUrl('/storage/videos/a.webm'));
        foreach ([
            '/storage/videos/a.pdf', '/storage/videos/a', '/storage/posts/a.mp4', '/storage/videos/nested/a.mp4',
            '/storage/videos/../a.mp4', '/storage/videos/', 'https://example.org/storage/videos/a.mp4',
            'https://youtu.be/abc', 'videos/a.mp4',
        ] as $url) {
            $this->assertNull(Storage::videoKeyFromUrl($url), $url);
        }
    }

    public function test_video_key_from_url_also_reads_the_bucket_url_and_the_route_url(): void
    {
        $this->useFakeBucket('https://cdn.example.org/sic');
        $key = 'videos/0123456789abcdef0123456789abcdef.mp4';
        $this->assertSame("https://cdn.example.org/sic/{$key}", Storage::publicUrl($key));
        $this->assertSame("/storage/{$key}", Storage::storageRouteUrl($key));
        $this->assertSame($key, Storage::videoKeyFromUrl(Storage::storageRouteUrl($key)));
        $this->assertSame($key, Storage::videoKeyFromUrl(Storage::publicUrl($key)));
        $this->assertNull(Storage::videoKeyFromUrl('https://evil.example.org/sic/videos/a.mp4'));
        $this->expectExceptionMessage('Invalid storage key');
        Storage::storageRouteUrl('../x');
    }

    public function test_video_limits_and_prefix(): void
    {
        $this->assertSame(100 * Storage::MB, Storage::limit('video'));
        $this->assertSame(VideoTypes::MAX_BYTES, Storage::limit('video'));
        $this->assertContains('videos', Storage::PREFIXES);
    }

    public function test_streamed_uploads_are_local_only(): void
    {
        $this->useFakeBucket();
        $this->expectExceptionMessage('Streamed uploads require STORAGE_DRIVER=local');
        Storage::receiveUpload('videos/a.mp4', $this->stream('x'), 10);
    }

    // ---- storage/range.test.ts (local driver) ----------------------------------------------------------------

    private function bytes(): string
    {
        return implode('', array_map(fn (int $i) => chr($i % 251), range(0, 999)));
    }

    public function test_it_serves_the_requested_slice_with_the_total_size_and_the_resolved_range(): void
    {
        Storage::putBytes('videos/a.mp4', $this->bytes(), 'video/mp4');

        $whole = Storage::read('videos/a.mp4');
        $this->assertSame(1000, $whole->size);
        $this->assertNull($whole->range);
        $whole->close();

        $head = Storage::read('videos/a.mp4', ['start' => 0, 'end' => 99]);
        $this->assertSame(['start' => 0, 'end' => 99], $head->range);
        $this->assertSame(1000, $head->size);
        $this->assertSame(substr($this->bytes(), 0, 100), $head->contents());

        $tail = Storage::read('videos/a.mp4', ['suffix' => 10]);
        $this->assertSame(['start' => 990, 'end' => 999], $tail->range);
        $this->assertSame(substr($this->bytes(), 990), $tail->contents());

        $open = Storage::read('videos/a.mp4', ['start' => 995, 'end' => 5000]);
        $this->assertSame(['start' => 995, 'end' => 999], $open->range);
        $this->assertSame(substr($this->bytes(), 995), $open->contents());
    }

    public function test_it_throws_for_an_unsatisfiable_range_and_returns_null_for_a_missing_object(): void
    {
        Storage::putBytes('videos/b.mp4', $this->bytes(), 'video/mp4');
        try {
            Storage::read('videos/b.mp4', ['start' => 1000]);
            $this->fail('the range must be unsatisfiable');
        } catch (RangeNotSatisfiableException $error) {
            $this->assertSame(1000, $error->size);
        }
        $this->assertNull(Storage::read('videos/missing.mp4', ['start' => 0]));
    }

    public function test_head_reports_the_size_without_opening_the_body(): void
    {
        Storage::putBytes('videos/c.mp4', $this->bytes(), 'video/mp4');
        $this->assertSame(1000, Storage::head('videos/c.mp4'));
        $this->assertNull(Storage::head('videos/none.mp4'));
    }

    public function test_receive_upload_stores_a_body_within_the_limit_and_reports_its_size(): void
    {
        $this->assertSame(1000, Storage::receiveUpload('videos/d.mp4', $this->stream($this->bytes()), 1000));
        $this->assertSame($this->bytes(), Storage::read('videos/d.mp4')->contents());
    }

    public function test_receive_upload_stops_and_leaves_nothing_behind_once_the_body_passes_the_limit(): void
    {
        try {
            Storage::receiveUpload('videos/e.mp4', $this->stream($this->bytes()), 999);
            $this->fail('the upload must be refused');
        } catch (UploadTooLargeException $error) {
            $this->assertSame(999, $error->maxBytes);
        }
        $this->assertNull(Storage::read('videos/e.mp4'));
        $this->assertSame([], glob(dirname(Storage::resolve('videos/e.mp4')).'/e.mp4*') ?: []);
    }

    public function test_receive_upload_removes_the_partial_file_when_the_body_fails(): void
    {
        $failing = fopen('php://memory', 'r');
        fclose($failing);
        try {
            @Storage::receiveUpload('videos/g.mp4', $failing, 1000);
            $this->fail('a closed stream must fail');
        } catch (\Throwable) {
            // expected
        }
        $this->assertSame([], glob(dirname(Storage::resolve('videos/g.mp4')).'/g.mp4*') ?: []);
    }

    public function test_receive_upload_returns_the_short_length_of_a_body_that_ends_early(): void
    {
        $this->assertSame(10, Storage::receiveUpload('videos/h.mp4', $this->stream(substr($this->bytes(), 0, 10)), 1000));
    }

    public function test_the_fake_bucket_answers_ranges_too(): void
    {
        $this->useFakeBucket();
        Storage::putBytes('videos/a.mp4', $this->bytes(), 'video/mp4');
        $slice = Storage::read('videos/a.mp4', ['start' => 10, 'end' => 19]);
        $this->assertSame(['start' => 10, 'end' => 19], $slice->range);
        $this->assertSame(substr($this->bytes(), 10, 10), $slice->contents());
        $this->assertSame(1000, Storage::head('videos/a.mp4'));
        $this->assertNull(Storage::head('videos/none.mp4'));
        $this->expectException(RangeNotSatisfiableException::class);
        Storage::read('videos/a.mp4', ['start' => 1000]);
    }

    public function test_remove_all_removes_the_variants_with_the_original(): void
    {
        foreach (['posts/a.webp', 'posts/a.w640.webp', 'posts/b.webp'] as $key) {
            Storage::putBytes($key, 'x', 'image/webp');
        }
        Storage::removeAll(['posts/a.webp', null]);
        $this->assertNull(Storage::head('posts/a.webp'));
        $this->assertNull(Storage::head('posts/a.w640.webp'));
        $this->assertSame(1, Storage::head('posts/b.webp'));
    }
}
