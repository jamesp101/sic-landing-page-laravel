<?php

namespace Tests\Unit\Carousel;

use App\Carousel\CarouselAdmin as Admin;
use App\Carousel\Overlay;
use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Port of carousel-admin.test.ts. Files live on a private temporary `uploads` root. */
class CarouselAdminTest extends TestCase
{
    use RefreshDatabase;
    use StoresFiles;

    private const KEY = 'videos/0123456789abcdef0123456789abcdef.mp4';

    private const YOUTUBE = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private array $none = ['videoPath' => null, 'videoUrl' => null];

    private array $content = ['overlay' => null, 'title' => null, 'subtitle' => null, 'linkUrl' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function slide(array $columns = []): CarouselItem
    {
        return CarouselItem::query()->forceCreate(['image_path' => $this->stored('carousels/'.(++$this->objects).'.webp'), ...$columns])->fresh();
    }

    private function claimError(string $raw, ?int $slideId = null): ?string
    {
        $result = Admin::claimSlideVideo($raw, $slideId);

        return $result['ok'] ? null : $result['error'];
    }

    // ---- parseSlideVideo ---------------------------------------------------------------------------------------

    public function test_parse_slide_video_matches_the_ts_implementation(): void
    {
        $parsed = json_decode((string) file_get_contents(__DIR__.'/fixtures/slides.json'), true)['parsed'];
        $this->assertNotEmpty($parsed);
        foreach ($parsed as $case) {
            $expected = $case;
            unset($expected['input']);
            $this->assertSame($expected, Admin::parseSlideVideo($case['input']), json_encode($case['input']));
        }
    }

    public function test_empty_means_no_video(): void
    {
        $this->assertSame(['ok' => true, 'video' => $this->none], Admin::parseSlideVideo(''));
        $this->assertSame(['ok' => true, 'video' => $this->none], Admin::parseSlideVideo('   '));
    }

    public function test_one_of_our_uploads_becomes_video_path(): void
    {
        $this->assertSame(['ok' => true, 'video' => ['videoPath' => self::KEY, 'videoUrl' => null]], Admin::parseSlideVideo('/storage/'.self::KEY));
        $this->assertSame('videos/0123456789abcdef0123456789abcdef.webm', Admin::parseSlideVideo('/storage/videos/0123456789abcdef0123456789abcdef.webm')['video']['videoPath']);
    }

    public function test_youtube_links_become_a_canonical_video_url(): void
    {
        $expected = ['ok' => true, 'video' => ['videoPath' => null, 'videoUrl' => self::YOUTUBE]];
        foreach ([self::YOUTUBE, 'https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'https://www.youtube.com/shorts/dQw4w9WgXcQ'] as $url) {
            $this->assertSame($expected, Admin::parseSlideVideo($url), $url);
        }
    }

    public function test_everything_else_is_rejected(): void
    {
        foreach ([
            'https://vimeo.com/123456789', 'https://example.com/clip.mp4', '/storage/carousels/a.webp',
            '/storage/videos/0123456789abcdef0123456789abcdef.exe', '/storage/videos/../secret.mp4', 'javascript:alert(1)', 'not a url',
        ] as $url) {
            $this->assertFalse(Admin::parseSlideVideo($url)['ok'], $url);
        }
    }

    // ---- claimSlideVideo ---------------------------------------------------------------------------------------

    public function test_a_missing_file_cannot_be_adopted(): void
    {
        $this->assertMatchesRegularExpression('/not found/', $this->claimError('/storage/videos/ffffffffffffffffffffffffffffffff.mp4'));
    }

    public function test_a_video_another_slide_or_some_content_uses_cannot_be_adopted_and_the_owner_may_keep_it(): void
    {
        $key = $this->stored();
        $url = "/storage/{$key}";
        $owner = $this->slide(['video_path' => $key]);
        $this->assertMatchesRegularExpression('/already used/', $this->claimError($url));
        $this->assertMatchesRegularExpression('/already used/', $this->claimError($url, $owner->id + 1));
        $this->assertNull($this->claimError($url, $owner->id));

        $free = $this->stored();
        $this->assertNull($this->claimError("/storage/{$free}"));
        Setting::query()->forceCreate(['key' => 'welcome_hero', 'value' => '{"url":"/storage/'.$free.'"}']);
        $this->assertMatchesRegularExpression('/already used/', $this->claimError("/storage/{$free}"));
    }

    public function test_content_that_mentions_a_video_counts_as_using_it(): void
    {
        $key = $this->stored();
        $this->assertFalse(Admin::videoUsedElsewhere($key));
        Post::query()->forceCreate(['title' => 'P', 'slug' => 'p', 'content' => "see /storage/{$key}", 'type' => 'article', 'user_id' => User::factory()->create()->id]);
        $this->assertTrue(Admin::videoUsedElsewhere($key));
    }

    public function test_no_video_and_a_youtube_link_need_no_file(): void
    {
        $this->assertSame(['ok' => true, 'video' => $this->none], Admin::claimSlideVideo(''));
        $this->assertNull($this->claimError('https://youtu.be/dQw4w9WgXcQ'));
    }

    // ---- createSlide -------------------------------------------------------------------------------------------

    public function test_create_stores_the_photo_adopts_the_video_and_goes_last(): void
    {
        $this->slide(['order' => 5]);
        $key = $this->stored();
        $created = Admin::createSlide(['content' => $this->content, 'image' => $this->photo(), 'video' => ['videoPath' => $key, 'videoUrl' => null], 'departmentId' => null, 'showOnMain' => true]);
        $this->assertSame([$key, 6, true], [$created->video_path, $created->order, $created->show_on_main]);
        $this->assertTrue($this->has($created->image_path));
        $this->assertTrue($this->has($key));
    }

    public function test_a_failed_commit_removes_the_stored_photo_and_the_adopted_video(): void
    {
        $key = $this->stored();
        $before = $this->keysUnder('carousels');
        try {
            Admin::createSlide(['content' => $this->content, 'image' => $this->photo(), 'video' => ['videoPath' => $key, 'videoUrl' => null], 'departmentId' => 999_999, 'showOnMain' => true]);
            $this->fail('The commit should fail on the unknown department.');
        } catch (QueryException) {
            // expected
        }
        $this->assertFalse($this->has($key));
        $this->assertSame($before, $this->keysUnder('carousels'));
    }

    // ---- duplicateSlide ----------------------------------------------------------------------------------------

    private function layer(string $src): array
    {
        return ['type' => 'image', 'id' => 'l1', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 20, 'rotation' => 0, 'opacity' => 100, 'src' => $src, 'alt' => 'Logo', 'radius' => 'none'];
    }

    public function test_duplicate_copies_photo_layer_images_and_video_and_shifts_later_slides(): void
    {
        $layerKey = $this->stored('carousels/layer-'.(++$this->objects).'.webp');
        $videoKey = $this->stored();
        $src = "/storage/{$layerKey}";
        $original = $this->slide(['video_path' => $videoKey, 'order' => 1, 'overlay' => Overlay::serializeOverlay([$this->layer($src)])]);
        $later = $this->slide(['order' => 2]);

        $copy = Admin::duplicateSlide($original);

        $this->assertSame(2, $copy->order);
        $this->assertSame(3, $later->fresh()->order);
        $this->assertNotSame($original->image_path, $copy->image_path);
        $this->assertTrue($this->has($copy->image_path));
        $this->assertNotSame($videoKey, $copy->video_path);
        $this->assertTrue($this->has($copy->video_path));
        [$copied] = Admin::overlayImageSrcs($copy->overlay);
        $this->assertNotSame($src, $copied);
        $this->assertTrue($this->has(Admin::ownedImageKey($copied)));

        // Deleting the original leaves the copy whole.
        Admin::deleteSlide($original);
        $this->assertTrue($this->has($copy->image_path) && $this->has($copy->video_path) && $this->has(Admin::ownedImageKey($copied)));
        $this->assertFalse($this->has($layerKey));
    }

    public function test_a_failed_duplicate_leaves_no_stray_files(): void
    {
        $original = $this->slide(['video_path' => $this->stored(), 'order' => 1]);
        $files = fn () => [...$this->keysUnder('carousels'), ...$this->keysUnder('videos')];
        $before = $files();

        CarouselItem::creating(fn () => throw new Exception('boom'));
        try {
            Admin::duplicateSlide($original);
            $this->fail('The duplicate should fail.');
        } catch (Exception $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($before, $files());
        $this->assertSame(1, CarouselItem::query()->count());
    }

    // ---- updateSlide -------------------------------------------------------------------------------------------

    public function test_replacing_a_video_removes_the_old_file_and_keeps_the_new_one(): void
    {
        $oldKey = $this->stored();
        $newKey = $this->stored();
        $row = $this->slide(['video_path' => $oldKey]);
        $saved = Admin::updateSlide($row, ['content' => $this->content, 'video' => ['videoPath' => $newKey, 'videoUrl' => null]]);
        $this->assertSame($newKey, $saved->video_path);
        $this->assertFalse($this->has($oldKey));
        $this->assertTrue($this->has($newKey));
        $this->assertTrue($this->has($row->image_path));
    }

    public function test_removing_the_video_or_switching_to_youtube_removes_the_file(): void
    {
        $first = $this->stored();
        $cleared = Admin::updateSlide($this->slide(['video_path' => $first]), ['content' => $this->content, 'video' => $this->none]);
        $this->assertSame([null, null], [$cleared->video_path, $cleared->video_url]);
        $this->assertFalse($this->has($first));

        $second = $this->stored();
        $youtube = Admin::updateSlide($this->slide(['video_path' => $second]), ['content' => $this->content, 'video' => ['videoPath' => null, 'videoUrl' => self::YOUTUBE]]);
        $this->assertSame([null, self::YOUTUBE], [$youtube->video_path, $youtube->video_url]);
        $this->assertFalse($this->has($second));
    }

    public function test_an_omitted_or_unchanged_video_keeps_its_file(): void
    {
        $key = $this->stored();
        $row = $this->slide(['video_path' => $key]);
        Admin::updateSlide($row, ['content' => $this->content]);
        $this->assertTrue($this->has($key));
        Admin::updateSlide($row, ['content' => $this->content, 'video' => ['videoPath' => $key, 'videoUrl' => null]]);
        $this->assertTrue($this->has($key));
        $this->assertSame($key, $row->fresh()->video_path);
    }

    public function test_a_failed_commit_keeps_the_old_file_and_removes_the_newly_adopted_one(): void
    {
        $oldKey = $this->stored();
        $newKey = $this->stored();
        $row = $this->slide(['video_path' => $oldKey]);
        CarouselItem::updating(fn () => throw new Exception('boom'));
        try {
            Admin::updateSlide($row, ['content' => $this->content, 'video' => ['videoPath' => $newKey, 'videoUrl' => null]]);
            $this->fail('The update should fail.');
        } catch (Exception) {
            // expected
        }
        $this->assertTrue($this->has($oldKey));
        $this->assertFalse($this->has($newKey));
    }

    public function test_replacing_the_photo_removes_the_old_photo_and_keeps_the_video(): void
    {
        $key = $this->stored();
        $row = $this->slide(['video_path' => $key]);
        $oldImage = $row->image_path;
        $saved = Admin::updateSlide($row, ['content' => $this->content, 'image' => $this->photo()]);
        $this->assertNotSame($oldImage, $saved->image_path);
        $this->assertFalse($this->has($oldImage));
        $this->assertTrue($this->has($key));
    }

    public function test_dropping_a_layer_image_removes_its_file_unless_another_slide_still_uses_it(): void
    {
        $unique = $this->stored('carousels/layer-unique.webp');
        $shared = $this->stored('carousels/layer-shared.webp');
        $overlay = Overlay::serializeOverlay([$this->layer("/storage/{$unique}"), [...$this->layer("/storage/{$shared}"), 'id' => 'l2']]);
        $row = $this->slide(['overlay' => $overlay]);
        $this->slide(['overlay' => Overlay::serializeOverlay([$this->layer("/storage/{$shared}")])]);

        Admin::updateSlide($row, ['content' => ['overlay' => Overlay::serializeOverlay([]), 'title' => null, 'subtitle' => null, 'linkUrl' => null]]);

        $this->assertFalse($this->has($unique));
        $this->assertTrue($this->has($shared));
    }

    // ---- deleteSlide -------------------------------------------------------------------------------------------

    public function test_delete_removes_the_photo_the_uploaded_video_and_the_row(): void
    {
        $key = $this->stored();
        $row = $this->slide(['video_path' => $key]);
        $result = Admin::deleteSlide($row);
        $this->assertSame(['id' => $row->id], $result);
        $this->assertSame(0, CarouselItem::query()->whereKey($row->id)->count());
        $this->assertFalse($this->has($key));
        $this->assertFalse($this->has($row->image_path));
    }

    public function test_a_youtube_slide_has_no_file_to_remove_besides_the_photo(): void
    {
        $row = $this->slide(['video_url' => self::YOUTUBE]);
        Admin::deleteSlide($row);
        $this->assertFalse($this->has($row->image_path));
    }

    public function test_only_images_under_carousels_are_owned(): void
    {
        $this->assertSame('carousels/a.webp', Admin::ownedImageKey('/storage/carousels/a.webp'));
        foreach (['/storage/posts/a.webp', '/storage/carousels/x/a.webp', 'https://cdn.test/a.webp', '/images/a.webp'] as $src) {
            $this->assertNull(Admin::ownedImageKey($src), $src);
        }
    }

    public function test_assign_orders_reuses_values_and_forces_a_strict_sequence(): void
    {
        $this->assertSame([1, 3, 7], Admin::assignOrders([7, 1, 3]));
        $this->assertSame([0, 1, 2], Admin::assignOrders([0, 0, 0]));
        $this->assertSame([2, 3, 4], Admin::assignOrders([2, 2, 3]));
    }

    public function test_a_video_a_department_references_cannot_be_adopted(): void
    {
        foreach (['hero_content', 'description'] as $i => $column) {
            $key = $this->stored();
            Department::query()->forceCreate(['name' => "D{$i}", 'slug' => "d{$i}", $column => '{"video":"/storage/'.$key.'"}']);
            $this->assertTrue(Admin::videoUsedElsewhere($key), $column);
            $this->assertMatchesRegularExpression('/already used/', $this->claimError("/storage/{$key}"));
        }
    }

    public function test_a_layer_image_another_slide_references_by_another_url_form_is_kept(): void
    {
        config(['sic.storage.public_url' => 'https://cdn.test', 'sic.storage.driver' => 'local']);
        $key = $this->stored('carousels/layer-cdn.webp');
        $this->slide(['overlay' => '{"v":1,"layers":[{"src":"https://cdn.test/'.$key.'"}]}']);
        $this->assertSame([], Admin::unusedLayerImageKeys(["/storage/{$key}"]));
        $free = $this->stored('carousels/layer-free.webp');
        $this->assertSame([$free], Admin::unusedLayerImageKeys(["/storage/{$free}"]));
    }
}
