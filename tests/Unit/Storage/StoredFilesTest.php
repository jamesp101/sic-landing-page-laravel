<?php

namespace Tests\Unit\Storage;

use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\RequestError;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class StoredFilesTest extends TestCase
{
    use UsesUploadsDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function putKeys(string ...$keys): void
    {
        foreach ($keys as $key) {
            Storage::putBytes($key, 'x', 'image/webp');
        }
    }

    private function image(string $name = 'a.webp', string $target = 'highlight')
    {
        return ImageFile::upload($this->upload($this->imageBytes(1000, 500, 'png'), $name, 'image/png'), $target);
    }

    // ---- image variants ----------------------------------------------------------------------------------------

    public function test_it_stores_the_variant_in_the_same_commit_and_removes_the_old_original_with_its_variant(): void
    {
        $this->putKeys('posts/old.webp', 'posts/old.w640.webp');
        $key = StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image(), 'variant' => true]],
            'run' => fn ($keys) => $keys['image'],
            'stale' => ['posts/old.webp'],
        ]);
        $expected = [$key, substr($key, 0, -5).'.w640.webp'];
        sort($expected);
        $this->assertSame($expected, $this->storedKeys());
        $this->assertMatchesRegularExpression('#^posts/[0-9a-f]{32}\.webp$#', $key);
    }

    public function test_it_removes_the_original_and_its_variant_when_the_commit_fails(): void
    {
        try {
            StoredFiles::commitWithFiles([
                'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image(), 'variant' => true]],
                'run' => fn () => throw new RuntimeException('db down'),
            ]);
            $this->fail('the commit must fail');
        } catch (RuntimeException $error) {
            $this->assertSame('db down', $error->getMessage());
        }
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_still_commits_when_the_variant_cannot_be_made(): void
    {
        Log::spy();
        // A file that is no image (and not converted, as a plain upload) has no decodable variant.
        $key = StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'posts', 'file' => $this->upload('not an image', 'a.webp', 'image/webp'), 'variant' => true]],
            'run' => fn ($keys) => $keys['image'],
        ]);
        $this->assertSame([$key], $this->storedKeys());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_it_removes_the_variant_with_an_owned_file(): void
    {
        $this->putKeys('posts/a.webp', 'posts/a.w640.webp');
        StoredFiles::deleteWithFiles(fn () => 1, ['posts/a.webp']);
        $this->assertSame([], $this->storedKeys());
    }

    // ---- commitWithFiles ---------------------------------------------------------------------------------------

    public function test_it_stores_the_new_file_commits_with_its_key_and_removes_the_old_key(): void
    {
        $this->putKeys('posts/old.webp');
        $result = StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image()]],
            'run' => fn ($keys) => ['saved' => $keys['image']],
            'stale' => ['posts/old.webp'],
        ]);
        $this->assertSame([$result['saved']], $this->storedKeys());
    }

    public function test_it_converts_an_image_upload_to_webp_and_stores_a_plain_file_as_is(): void
    {
        $keys = StoredFiles::commitWithFiles([
            'files' => [
                'image' => ['prefix' => 'posts', 'file' => $this->image('photo.png')],
                'doc' => ['prefix' => 'downloads', 'file' => $this->upload('%PDF', 'f.pdf', 'application/pdf')],
            ],
            'run' => fn ($keys) => $keys,
        ]);
        $this->assertStringEndsWith('.webp', $keys['image']);
        $this->assertSame('RIFF', substr(Storage::read($keys['image'])->contents(), 0, 4));
        $this->assertSame('%PDF', Storage::read($keys['doc'])->contents());
    }

    public function test_it_removes_the_new_file_and_rethrows_when_the_commit_fails_keeping_the_old_file(): void
    {
        $this->putKeys('posts/old.webp');
        try {
            StoredFiles::commitWithFiles([
                'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image()]],
                'run' => fn () => throw new RuntimeException('db down'),
                'stale' => ['posts/old.webp'],
            ]);
            $this->fail('the commit must fail');
        } catch (RuntimeException $error) {
            $this->assertSame('db down', $error->getMessage());
        }
        $this->assertSame(['posts/old.webp'], $this->storedKeys());
    }

    public function test_it_keeps_the_new_file_and_returns_the_result_when_removing_the_old_file_fails(): void
    {
        Log::spy();
        // An invalid key makes the removal throw.
        $result = StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image()]],
            'run' => fn ($keys) => $keys['image'],
            'stale' => ['../old.webp'],
        ]);
        $this->assertSame([$result], $this->storedKeys());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_it_keeps_the_new_file_when_a_stale_key_lookup_fails_after_the_commit(): void
    {
        Log::spy();
        $result = StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'carousels', 'file' => $this->image()]],
            'run' => fn ($keys) => $keys['image'],
            'stale' => fn () => throw new RuntimeException('count failed'),
        ]);
        $this->assertSame([$result], $this->storedKeys());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_it_does_not_remove_old_keys_before_the_commit_has_run(): void
    {
        $this->putKeys('posts/old.webp');
        $stillThere = null;
        StoredFiles::commitWithFiles([
            'files' => ['image' => ['prefix' => 'posts', 'file' => $this->image()]],
            'run' => function () use (&$stillThere) {
                $stillThere = Storage::head('posts/old.webp') !== null;
            },
            'stale' => ['posts/old.webp'],
        ]);
        $this->assertTrue($stillThere);
        $this->assertNull(Storage::head('posts/old.webp'));
    }

    public function test_it_skips_empty_uploads_and_null_stale_keys(): void
    {
        $result = StoredFiles::commitWithFiles([
            'files' => ['image' => null, 'other' => null],
            'run' => fn ($keys) => $keys,
            'stale' => [null, ''],
        ]);
        $this->assertSame(['image' => null, 'other' => null], $result);
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_removes_already_stored_files_when_a_later_one_fails(): void
    {
        try {
            StoredFiles::commitWithFiles([
                'files' => [
                    'a' => ['prefix' => 'posts', 'file' => $this->image()],
                    // Declared as an image but not decodable: the conversion fails after `a` was stored.
                    'b' => ['prefix' => 'posts', 'file' => ImageFile::upload($this->upload('nope', 'b.png', 'image/png'), 'highlight')],
                ],
                'run' => fn () => 'never',
            ]);
            $this->fail('the second file must be rejected');
        } catch (RequestError $error) {
            $this->assertSame('The image could not be read. Choose another file.', $error->getMessage());
        }
        $this->assertSame([], $this->storedKeys());
    }

    public function test_it_removes_adopted_keys_when_the_commit_fails_and_keeps_them_when_it_succeeds(): void
    {
        $this->putKeys('downloads/a.pdf', 'downloads/b.pdf');
        try {
            StoredFiles::commitWithFiles(['adopted' => ['downloads/a.pdf'], 'run' => fn () => throw new RuntimeException('db down')]);
            $this->fail('the commit must fail');
        } catch (RuntimeException) {
        }
        StoredFiles::commitWithFiles(['adopted' => ['downloads/b.pdf'], 'run' => fn () => 'ok']);
        $this->assertSame(['downloads/b.pdf'], $this->storedKeys());
    }

    // ---- deleteWithFiles ---------------------------------------------------------------------------------------

    public function test_delete_removes_the_owned_keys_after_the_row_delete(): void
    {
        $this->putKeys('a', 'b');
        $seen = null;
        $result = StoredFiles::deleteWithFiles(function () use (&$seen) {
            $seen = count($this->storedKeys());

            return ['id' => 1];
        }, ['a', null, 'b']);
        $this->assertSame(['id' => 1], $result);
        $this->assertSame(2, $seen);
        $this->assertSame([], $this->storedKeys());
    }

    public function test_delete_keeps_every_file_when_the_row_delete_fails(): void
    {
        $this->putKeys('a');
        try {
            StoredFiles::deleteWithFiles(fn () => throw new RuntimeException('db down'), ['a']);
            $this->fail('the delete must fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(['a'], $this->storedKeys());
    }

    public function test_delete_logs_a_failing_removal_still_removes_the_others_and_returns_the_result(): void
    {
        Log::spy();
        $this->putKeys('b');
        $result = StoredFiles::deleteWithFiles(fn () => 'deleted', ['../a', 'b']);
        $this->assertSame('deleted', $result);
        $this->assertSame([], $this->storedKeys());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_delete_accepts_a_function_that_resolves_the_keys_after_the_delete(): void
    {
        $this->putKeys('a');
        StoredFiles::deleteWithFiles(fn () => null, fn () => ['a']);
        $this->assertSame([], $this->storedKeys());
    }

    // ---- discardFiles ------------------------------------------------------------------------------------------

    public function test_discard_removes_the_keys_logs_a_failing_removal_and_never_throws(): void
    {
        Log::spy();
        $this->putKeys('b');
        StoredFiles::discardFiles(['../a', 'b', null], 'rejected upload');
        $this->assertSame([], $this->storedKeys());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Stored file cleanup failed (rejected upload): ../a');
    }
}
