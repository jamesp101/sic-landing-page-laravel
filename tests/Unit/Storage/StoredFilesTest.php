<?php

namespace Tests\Unit\Storage;

use App\Carousel\Overlay;
use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Download;
use App\Models\Member;
use App\Models\Post;
use App\Models\User;
use App\Storage\ImageFile;
use App\Storage\Storage;
use App\Storage\StoredFiles;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class StoredFilesTest extends TestCase
{
    use RefreshDatabase;
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

    private int $rows = 0;

    private function makePost(array $columns = []): Post
    {
        $n = ++$this->rows;

        return Post::query()->create(['title' => "P{$n}", 'slug' => "p{$n}", 'content' => '', 'type' => 'article', 'user_id' => User::factory()->create()->id, ...$columns]);
    }

    private function department(array $columns = []): Department
    {
        $n = ++$this->rows;

        return Department::query()->create(['name' => "D{$n}", 'slug' => "d{$n}", ...$columns]);
    }

    private function slide(array $columns = []): CarouselItem
    {
        return CarouselItem::query()->create(['image_path' => 'carousels/'.++$this->rows.'.webp', ...$columns]);
    }

    private function download(array $columns = []): Download
    {
        return Download::query()->create(['file_name' => 'f', 'file_path' => 'downloads/a.pdf', 'file_size' => 1, 'user_id' => User::factory()->create()->id, ...$columns]);
    }

    private function layer(string $key): array
    {
        return ['type' => 'image', 'id' => 'l1', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 20, 'rotation' => 0, 'opacity' => 100, 'src' => "/storage/{$key}", 'alt' => 'Logo', 'radius' => 'none'];
    }

    private function image(string $name = 'a.webp', string $target = 'highlight')
    {
        return ImageFile::upload($this->upload($this->imageBytes(1000, 500, 'png'), $name, 'image/png'), $target);
    }

    // ---- save: files the row declares --------------------------------------------------------------------------

    public function test_save_stores_the_declared_column_with_its_variant_and_removes_the_replaced_original_with_its_variant(): void
    {
        $this->putKeys('posts/old.webp', 'posts/old.w640.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/old.webp']);

        StoredFiles::save($post, ['title' => 'New'], ['highlight_image_path' => $this->image()]);

        $key = $post->fresh()->highlight_image_path;
        $this->assertMatchesRegularExpression('#^posts/[0-9a-f]{32}\.webp$#', $key);
        $expected = [$key, substr($key, 0, -5).'.w640.webp'];
        sort($expected);
        $this->assertSame($expected, $this->storedKeys());
        $this->assertSame('New', $post->fresh()->title);
    }

    public function test_save_creates_a_new_row_with_the_stored_key(): void
    {
        $post = StoredFiles::save(new Post, ['title' => 'T', 'slug' => 't', 'content' => '', 'type' => 'article', 'user_id' => User::factory()->create()->id], ['highlight_image_path' => $this->image()]);

        $this->assertTrue($post->exists);
        $this->assertCount(2, $this->storedKeys());
        $this->assertContains($post->fresh()->highlight_image_path, $this->storedKeys());
    }

    public function test_save_keeps_the_column_and_its_file_when_no_upload_comes(): void
    {
        $this->putKeys('posts/old.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/old.webp']);

        StoredFiles::save($post, ['title' => 'New'], ['highlight_image_path' => null]);

        $this->assertSame('posts/old.webp', $post->fresh()->highlight_image_path);
        $this->assertSame(['posts/old.webp'], $this->storedKeys());
    }

    public function test_save_removes_the_original_and_its_variant_when_the_write_fails_keeping_the_old_file(): void
    {
        $this->putKeys('posts/old.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/old.webp']);
        try {
            StoredFiles::save($post, ['title' => 'New'], ['highlight_image_path' => $this->image()], write: fn () => throw new RuntimeException('db down'));
            $this->fail('the write must fail');
        } catch (RuntimeException $error) {
            $this->assertSame('db down', $error->getMessage());
        }
        $this->assertSame(['posts/old.webp'], $this->storedKeys());
        $this->assertSame('posts/old.webp', $post->fresh()->highlight_image_path);
    }

    public function test_save_still_commits_when_the_variant_cannot_be_made(): void
    {
        Log::spy();
        // A file that is no image (and not converted, as a plain upload) has no decodable variant.
        $post = $this->makePost();
        StoredFiles::save($post, [], ['highlight_image_path' => $this->upload('not an image', 'a.webp', 'image/webp')]);

        $this->assertSame([$post->fresh()->highlight_image_path], $this->storedKeys());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_save_converts_an_image_upload_to_webp_and_stores_a_plain_file_as_is(): void
    {
        $post = $this->makePost();
        StoredFiles::save($post, [], ['highlight_image_path' => $this->image('photo.png')]);
        $download = StoredFiles::save(new Download, ['file_name' => 'f', 'file_size' => 4, 'user_id' => $post->user_id], ['file_path' => $this->upload('%PDF', 'f.pdf', 'application/pdf')]);

        $imageKey = $post->fresh()->highlight_image_path;
        $this->assertStringEndsWith('.webp', $imageKey);
        $this->assertSame('RIFF', substr(Storage::read($imageKey)->contents(), 0, 4));
        $this->assertStringStartsWith('downloads/', $download->file_path);
        $this->assertSame('%PDF', Storage::read($download->file_path)->contents());
    }

    public function test_save_keeps_the_new_file_and_returns_the_row_when_removing_the_old_file_fails(): void
    {
        Log::spy();
        // An invalid key makes the removal throw.
        $post = $this->makePost(['highlight_image_path' => '../old.webp']);

        StoredFiles::save($post, [], ['highlight_image_path' => $this->image()]);

        $this->assertContains($post->fresh()->highlight_image_path, $this->storedKeys());
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_save_does_not_remove_old_keys_before_the_write_has_run(): void
    {
        $this->putKeys('posts/old.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/old.webp']);
        $stillThere = null;

        StoredFiles::save($post, [], ['highlight_image_path' => $this->image()], write: function (Post $row, array $attributes) use (&$stillThere) {
            $stillThere = Storage::head('posts/old.webp') !== null;
            $row->fill($attributes)->save();
        });

        $this->assertTrue($stillThere);
        $this->assertNull(Storage::head('posts/old.webp'));
    }

    public function test_save_removes_already_stored_files_when_a_later_one_fails(): void
    {
        $slide = $this->slide();
        try {
            StoredFiles::save($slide, [], [
                'image_path' => $this->image(target: 'slide'),
                // Declared as an image but not decodable: the conversion fails after the first file was stored.
                'video_path' => ImageFile::upload($this->upload('nope', 'b.png', 'image/png'), 'highlight'),
            ]);
            $this->fail('the second file must be rejected');
        } catch (RequestError $error) {
            $this->assertSame('The image could not be read. Choose another file.', $error->getMessage());
        }
        $this->assertSame([], $this->storedKeys());
    }

    public function test_save_adopts_a_key_set_on_a_file_column_and_removes_the_replaced_one_after_the_write(): void
    {
        $this->putKeys('downloads/a.pdf', 'downloads/b.pdf');
        $user = User::factory()->create();
        $columns = ['file_name' => 'f', 'file_size' => 1, 'user_id' => $user->id];

        try {
            StoredFiles::save(new Download, $columns + ['file_path' => 'downloads/a.pdf'], write: fn () => throw new RuntimeException('db down'));
            $this->fail('the write must fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(['downloads/b.pdf'], $this->storedKeys());

        $download = StoredFiles::save(new Download, $columns + ['file_path' => 'downloads/b.pdf']);
        $this->assertSame(['downloads/b.pdf'], $this->storedKeys());

        // Setting the column to the key it already holds adopts and removes nothing.
        try {
            StoredFiles::save($download, ['file_name' => 'g', 'file_path' => 'downloads/b.pdf'], write: fn () => throw new RuntimeException('db down'));
        } catch (RuntimeException) {
        }
        $this->assertSame(['downloads/b.pdf'], $this->storedKeys());
    }

    public function test_save_removes_an_uploaded_video_that_the_row_stops_using(): void
    {
        $this->putKeys('videos/old.mp4');
        $slide = $this->slide(['video_path' => 'videos/old.mp4']);

        StoredFiles::save($slide, ['video_path' => null]);

        $this->assertSame([], $this->storedKeys());
    }

    public function test_save_removes_layer_images_the_overlay_dropped_unless_another_slide_still_uses_them(): void
    {
        $this->putKeys('carousels/unique.webp', 'carousels/shared.webp');
        $slide = $this->slide(['overlay' => Overlay::serializeOverlay([$this->layer('carousels/unique.webp'), [...$this->layer('carousels/shared.webp'), 'id' => 'l2']])]);
        $this->slide(['overlay' => Overlay::serializeOverlay([$this->layer('carousels/shared.webp')])]);

        StoredFiles::save($slide, ['overlay' => Overlay::serializeOverlay([])]);

        $this->assertSame(['carousels/shared.webp'], $this->storedKeys());
    }

    public function test_save_rejects_an_upload_for_a_column_the_model_does_not_declare(): void
    {
        $this->expectException(LogicException::class);
        StoredFiles::save($this->makePost(), [], ['title' => $this->image()]);
    }

    // ---- delete ------------------------------------------------------------------------------------------------

    public function test_delete_removes_the_owned_file_and_its_variant_after_the_row_delete(): void
    {
        $this->putKeys('posts/a.webp', 'posts/a.w640.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/a.webp']);
        $seen = null;

        $result = StoredFiles::delete($post, function () use ($post, &$seen) {
            $seen = count($this->storedKeys());

            return $post->delete();
        });

        $this->assertTrue($result);
        $this->assertSame(2, $seen);
        $this->assertSame([], $this->storedKeys());
        $this->assertNull(Post::query()->find($post->id));
    }

    public function test_delete_keeps_every_file_when_the_row_delete_fails(): void
    {
        $this->putKeys('posts/a.webp');
        $post = $this->makePost(['highlight_image_path' => 'posts/a.webp']);
        try {
            StoredFiles::delete($post, fn () => throw new RuntimeException('db down'));
            $this->fail('the delete must fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(['posts/a.webp'], $this->storedKeys());
    }

    public function test_delete_logs_a_failing_removal_still_removes_the_others_and_returns_the_result(): void
    {
        Log::spy();
        $this->putKeys('posts/b.webp');
        $department = $this->department();
        $this->makePost(['department_id' => $department->id, 'highlight_image_path' => '../a']);
        $this->makePost(['department_id' => $department->id, 'highlight_image_path' => 'posts/b.webp']);

        StoredFiles::delete($department);

        $this->assertSame([], $this->storedKeys());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_deleting_a_department_removes_every_file_it_owns_with_the_variants(): void
    {
        $this->putKeys(
            'departments/logo.webp', 'departments/logo.w128.webp',
            'members/m.webp', 'members/m.w128.webp',
            'carousels/s.webp', 'carousels/s.w960.webp', 'videos/s.mp4', 'carousels/layer.webp',
            'posts/p.webp', 'posts/p.w640.webp', 'downloads/d.pdf',
        );
        $department = $this->department(['logo_path' => 'departments/logo.webp']);
        Member::query()->create(['name' => 'M', 'role' => 'R', 'department_id' => $department->id, 'image_path' => 'members/m.webp']);
        $this->slide(['department_id' => $department->id, 'image_path' => 'carousels/s.webp', 'video_path' => 'videos/s.mp4', 'overlay' => Overlay::serializeOverlay([$this->layer('carousels/layer.webp')])]);
        $this->makePost(['department_id' => $department->id, 'highlight_image_path' => 'posts/p.webp']);
        $this->download(['department_id' => $department->id, 'file_path' => 'downloads/d.pdf']);

        StoredFiles::delete($department);

        $this->assertSame([], $this->storedKeys());
        $this->assertSame(0, Post::query()->count());
    }

    public function test_deleting_a_user_removes_their_posts_images_and_downloads_only(): void
    {
        $this->putKeys('posts/mine.webp', 'downloads/mine.pdf', 'posts/other.webp');
        $user = User::factory()->create();
        $this->makePost(['user_id' => $user->id, 'highlight_image_path' => 'posts/mine.webp']);
        $this->makePost(['highlight_image_path' => 'posts/other.webp']);
        $this->download(['user_id' => $user->id, 'file_path' => 'downloads/mine.pdf']);

        StoredFiles::delete($user);

        $this->assertSame(['posts/other.webp'], $this->storedKeys());
    }

    public function test_deleting_a_slide_keeps_a_layer_image_another_slide_uses(): void
    {
        $this->putKeys('carousels/s.webp', 'carousels/shared.webp', 'carousels/own.webp');
        $slide = $this->slide(['image_path' => 'carousels/s.webp', 'overlay' => Overlay::serializeOverlay([$this->layer('carousels/own.webp'), [...$this->layer('carousels/shared.webp'), 'id' => 'l2']])]);
        $this->slide(['overlay' => Overlay::serializeOverlay([$this->layer('carousels/shared.webp')])]);

        StoredFiles::delete($slide);

        $this->assertSame(['carousels/shared.webp'], $this->storedKeys());
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
