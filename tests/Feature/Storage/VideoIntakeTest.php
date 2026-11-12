<?php

namespace Tests\Feature\Storage;

use App\Storage\DirectUpload;
use App\Storage\PendingUploads;
use App\Storage\Storage;
use App\Storage\VideoIntake;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class VideoIntakeTest extends TestCase
{
    use RefreshDatabase;
    use UsesUploadsDisk;

    private const KEY = 'videos/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.mp4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function message(callable $call): string
    {
        try {
            $call();
        } catch (RequestError $error) {
            $this->assertSame(RequestError::BAD_REQUEST, $error->errorCode);

            return $error->getMessage();
        }
        $this->fail('expected a rejection');
    }

    private function sparse(string $key, int $size): void
    {
        $path = Storage::resolve($key);
        @mkdir(dirname($path), 0775, true);
        $handle = fopen($path, 'wb');
        ftruncate($handle, $size);
        fclose($handle);
    }

    // ---- video types -------------------------------------------------------------------------------------------

    public function test_it_accepts_mp4_and_webm_with_a_video_content_type_decided_by_the_extension(): void
    {
        $this->assertSame(['ok' => true, 'ext' => '.mp4', 'contentType' => 'video/mp4'], VideoIntake::validateVideoUpload(['fileName' => 'Clip.MP4', 'contentType' => 'video/mp4', 'size' => 5]));
        // The extension wins over the declared type, so the signed type matches what is served.
        $this->assertSame(['ok' => true, 'ext' => '.webm', 'contentType' => 'video/webm'], VideoIntake::validateVideoUpload(['fileName' => 'a.webm', 'contentType' => 'video/mp4', 'size' => 5]));
        $this->assertSame('.webm', VideoIntake::validateVideoUpload(['fileName' => 'clip', 'contentType' => 'video/webm', 'size' => 5])['ext']);
    }

    public function test_it_rejects_other_types_including_an_image_or_document_renamed_to_a_video_or_the_other_way_round(): void
    {
        foreach ([['a.mov', 'video/quicktime'], ['a.pdf', 'video/mp4'], ['a.mp4', 'application/pdf'], ['a.mp4', 'text/html'], ['a.png', 'image/png'], ['a.mp4', '']] as [$fileName, $contentType]) {
            $this->assertFalse(VideoIntake::validateVideoUpload(compact('fileName', 'contentType') + ['size' => 5])['ok'], "{$fileName} {$contentType}");
        }
    }

    public function test_it_enforces_the_size_limit(): void
    {
        $this->assertTrue(VideoIntake::validateVideoUpload(['fileName' => 'a.mp4', 'contentType' => 'video/mp4', 'size' => 100 * 1024 * 1024])['ok']);
        $this->assertSame(['ok' => false, 'message' => VideoIntake::VIDEO_TOO_LARGE], VideoIntake::validateVideoUpload(['fileName' => 'a.mp4', 'contentType' => 'video/mp4', 'size' => 100 * 1024 * 1024 + 1]));
    }

    public function test_it_issues_server_shaped_videos_keys_and_recognises_only_those(): void
    {
        $key = DirectUpload::newDirectUploadKey('a.mp4', 'video/mp4', DirectUpload::VIDEO_UPLOAD_PREFIX);
        $this->assertMatchesRegularExpression('#^videos/[0-9a-f]{32}\.mp4$#', $key);
        $this->assertTrue(VideoIntake::isVideoUploadKey($key));
        $this->assertTrue(VideoIntake::isVideoUploadKey(self::KEY));
        foreach ([
            'videos/'.str_repeat('b', 32).'.pdf', 'downloads/'.str_repeat('b', 32).'.mp4', 'videos/chosen-by-client.mp4', 'videos/../x.mp4', 'videos/'.str_repeat('b', 32),
        ] as $bad) {
            $this->assertFalse(VideoIntake::isVideoUploadKey($bad), $bad);
        }
        // A downloads key is not a videos key and the other way round.
        $this->assertFalse(DirectUpload::isDirectUploadKey(self::KEY));
    }

    // ---- complete ----------------------------------------------------------------------------------------------

    public function test_complete_checks_the_owner_then_the_object_and_its_size_and_only_then_claims(): void
    {
        $user = $this->actingAsSuperadmin();
        Storage::putBytes(self::KEY, str_repeat('x', 42), 'video/mp4');
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame(['key' => self::KEY, 'size' => 42], VideoIntake::complete($user->id, self::KEY));
        $this->assertFalse(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
        $this->assertNotNull(Storage::head(self::KEY), 'the object is kept');
    }

    public function test_complete_rejects_someone_elses_key_before_touching_the_object(): void
    {
        $owner = $this->actingAsSuperadmin();
        $this->sparse(self::KEY, Storage::limit('video') + 1);
        PendingUploads::registerPendingUpload(self::KEY, $owner->id);
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => VideoIntake::complete($owner->id + 1, self::KEY)));
        $this->assertNotNull(Storage::head(self::KEY));
        $this->assertTrue(PendingUploads::ownsPendingUpload(self::KEY, $owner->id));
    }

    public function test_complete_rejects_keys_the_server_did_not_issue(): void
    {
        $user = $this->actingAsSuperadmin();
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => VideoIntake::complete($user->id, 'videos/evil.mp4')));
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => VideoIntake::complete($user->id, 'downloads/'.str_repeat('a', 32).'.mp4')));
    }

    public function test_complete_does_not_burn_the_claim_when_the_object_is_missing(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame('The uploaded video was not found. Upload it again.', $this->message(fn () => VideoIntake::complete($user->id, self::KEY)));
        $this->assertTrue(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
    }

    public function test_complete_consumes_the_claim_and_removes_an_oversized_object_once(): void
    {
        $user = $this->actingAsSuperadmin();
        $this->sparse(self::KEY, Storage::limit('video') + 1);
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame(VideoIntake::VIDEO_TOO_LARGE, $this->message(fn () => VideoIntake::complete($user->id, self::KEY)));
        $this->assertFalse(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
        $this->assertNull(Storage::head(self::KEY));
    }

    public function test_complete_removes_an_empty_object_the_same_way(): void
    {
        $user = $this->actingAsSuperadmin();
        Storage::putBytes(self::KEY, '', 'video/mp4');
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame('The uploaded video is empty.', $this->message(fn () => VideoIntake::complete($user->id, self::KEY)));
        $this->assertNull(Storage::head(self::KEY));
    }

    public function test_complete_fails_when_the_claim_is_lost_to_a_concurrent_request(): void
    {
        $user = $this->actingAsSuperadmin();
        Storage::putBytes(self::KEY, 'x', 'video/mp4');
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        VideoIntake::complete($user->id, self::KEY);
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => VideoIntake::complete($user->id, self::KEY)));
        $this->assertNotNull(Storage::head(self::KEY));
    }

    // ---- receive (local) ---------------------------------------------------------------------------------------

    public function test_receive_streams_the_body_for_the_key_owner_with_the_announced_size_as_the_cap(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame(['size' => 10], VideoIntake::receive($user->id, self::KEY, 10, $this->stream('0123456789')));
        $this->assertSame('0123456789', Storage::read(self::KEY)->contents());
    }

    public function test_receive_checks_the_owner_first_and_never_reads_the_body_of_a_foreign_key(): void
    {
        $owner = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $owner->id);
        $body = $this->stream('0123456789');
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => VideoIntake::receive($owner->id + 1, self::KEY, 10, $body)));
        $this->assertSame(0, ftell($body));
        $this->assertNull(Storage::head(self::KEY));
    }

    public function test_receive_rejects_a_size_over_the_limit_before_reading_anything(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $body = $this->stream('x');
        $this->assertSame(VideoIntake::VIDEO_TOO_LARGE, $this->message(fn () => VideoIntake::receive($user->id, self::KEY, Storage::limit('video') + 1, $body)));
        $this->assertSame(0, ftell($body));
    }

    public function test_receive_rejects_a_missing_or_invalid_size_and_an_empty_body(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertSame('Choose a file to upload.', $this->message(fn () => VideoIntake::receive($user->id, self::KEY, 0, $this->stream('x'))));
        $this->assertSame('Choose a file to upload.', $this->message(fn () => VideoIntake::receive($user->id, self::KEY, 10, null)));
    }

    public function test_receive_reports_a_body_that_outgrows_the_announced_size_and_leaves_nothing(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertMatchesRegularExpression('/larger than the size/', $this->message(fn () => VideoIntake::receive($user->id, self::KEY, 10, $this->stream(str_repeat('x', 11)))));
        $this->assertNull(Storage::head(self::KEY));
    }

    public function test_receive_removes_a_file_that_is_shorter_than_announced(): void
    {
        $user = $this->actingAsSuperadmin();
        PendingUploads::registerPendingUpload(self::KEY, $user->id);
        $this->assertMatchesRegularExpression('/incomplete/', $this->message(fn () => VideoIntake::receive($user->id, self::KEY, 10, $this->stream('1234'))));
        $this->assertNull(Storage::head(self::KEY));
    }
}
