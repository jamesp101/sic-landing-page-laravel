<?php

namespace Tests\Feature\Storage;

use App\Storage\DownloadIntake;
use App\Storage\PendingUploads;
use App\Storage\Storage;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class DownloadIntakeTest extends TestCase
{
    use RefreshDatabase;
    use UsesUploadsDisk;

    private const KEY = 'downloads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeBucket();
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

    /** A stored object of `$size` bytes without writing them (sparse). */
    private function sparse(string $key, int $size): void
    {
        $path = Storage::resolve($key);
        @mkdir(dirname($path), 0775, true);
        $handle = fopen($path, 'wb');
        ftruncate($handle, $size);
        fclose($handle);
    }

    private function issued(int $userId): void
    {
        PendingUploads::registerPendingUpload(self::KEY, $userId);
    }

    public function test_multipart_stores_the_file_and_returns_its_key_and_size(): void
    {
        $this->useLocalUploads();
        $user = $this->actingAsSuperadmin();
        $accepted = DownloadIntake::acceptDownloadFile($user->id, ['file' => $this->upload('hello', 'a.pdf', 'application/pdf')]);
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.pdf$#', $accepted['key']);
        $this->assertSame(5, $accepted['size']);
        $this->assertSame('hello', Storage::read($accepted['key'])->contents());
    }

    public function test_it_requires_a_file_when_there_is_no_uploaded_key(): void
    {
        $user = $this->actingAsSuperadmin();
        $this->assertSame('Choose a file to upload.', $this->message(fn () => DownloadIntake::acceptDownloadFile($user->id, [])));
    }

    public function test_direct_upload_verifies_the_object_then_consumes_the_claim_and_returns_the_stored_size(): void
    {
        $user = $this->actingAsSuperadmin();
        Storage::putBytes(self::KEY, str_repeat('x', 42), 'application/pdf');
        $this->issued($user->id);
        $this->assertSame(['key' => self::KEY, 'size' => 42], DownloadIntake::acceptDownloadFile($user->id, ['uploadedKey' => self::KEY]));
        $this->assertFalse(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
        $this->assertNotNull(Storage::head(self::KEY));
    }

    public function test_it_does_not_consume_the_claim_when_the_object_is_missing(): void
    {
        $user = $this->actingAsSuperadmin();
        $this->issued($user->id);
        $this->assertSame('The uploaded file was not found. Upload it again.', $this->message(fn () => DownloadIntake::acceptDownloadFile($user->id, ['uploadedKey' => self::KEY])));
        $this->assertTrue(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
    }

    public function test_it_rejects_an_oversized_object_consuming_the_claim_and_removing_it(): void
    {
        $user = $this->actingAsSuperadmin();
        $this->sparse(self::KEY, Storage::limit('download') + 1);
        $this->issued($user->id);
        $this->assertSame(DownloadIntake::DOWNLOAD_TOO_LARGE, $this->message(fn () => DownloadIntake::acceptDownloadFile($user->id, ['uploadedKey' => self::KEY])));
        $this->assertFalse(PendingUploads::ownsPendingUpload(self::KEY, $user->id));
        $this->assertNull(Storage::head(self::KEY));
    }

    public function test_it_never_touches_an_object_whose_claim_is_not_the_callers(): void
    {
        $owner = $this->actingAsSuperadmin();
        $this->sparse(self::KEY, Storage::limit('download') + 1);
        $this->issued($owner->id);
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => DownloadIntake::acceptDownloadFile($owner->id + 1, ['uploadedKey' => self::KEY])));
        $this->assertNotNull(Storage::head(self::KEY));
        $this->assertTrue(PendingUploads::ownsPendingUpload(self::KEY, $owner->id));
    }

    public function test_it_rejects_keys_the_server_did_not_issue_and_direct_uploads_that_are_disabled(): void
    {
        $user = $this->actingAsSuperadmin();
        Storage::putBytes('downloads/evil.pdf', 'x', 'application/pdf');
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => DownloadIntake::acceptDownloadFile($user->id, ['uploadedKey' => 'downloads/evil.pdf'])));

        $this->useLocalUploads();
        Storage::putBytes(self::KEY, 'x', 'application/pdf');
        $this->issued($user->id);
        $this->assertSame('Invalid uploaded file.', $this->message(fn () => DownloadIntake::acceptDownloadFile($user->id, ['uploadedKey' => self::KEY])));
    }

    public function test_the_multipart_rules_state_the_download_limits_once(): void
    {
        $this->useLocalUploads();
        $rules = fn () => ['file' => ['required', ...DownloadIntake::downloadFile()]];
        $messages = ['file.required' => DownloadIntake::CHOOSE_FILE];
        $first = fn ($value) => Validator::make(['file' => $value], $rules(), $messages)->errors()->first('file');

        $this->assertSame('', $first($this->upload('x', 'form.pdf', 'application/pdf')));
        $this->assertSame('Choose a file to upload.', $first(null));
        $this->assertSame('Choose a file to upload.', $first($this->upload('', 'empty.pdf', 'application/pdf')));
        $this->assertSame('Videos cannot be uploaded as Downloads.', $first($this->upload('x', 'talk.mp4', 'video/mp4')));
        $this->assertSame('The file must not be larger than 20 MB.', $first($this->upload(str_repeat('x', Storage::limit('download') + 1), 'big.pdf', 'application/pdf')));
    }

    public function test_the_upload_mode_follows_the_driver(): void
    {
        $this->assertSame('direct', DownloadIntake::downloadUploadMode());
        $this->useLocalUploads();
        $this->assertSame('multipart', DownloadIntake::downloadUploadMode());
    }
}
