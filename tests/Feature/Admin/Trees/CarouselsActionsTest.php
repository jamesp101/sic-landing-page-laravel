<?php

namespace Tests\Feature\Admin\Trees;

use App\Carousel\Overlay;
use App\Models\CarouselItem;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Carousel\StoresFiles;

/** Authorization, input rules, messages and results of `carousels.*` seen from `POST /_actions/…`. */
class CarouselsActionsTest extends TestCase
{
    use RefreshDatabase;
    use StoresFiles;

    private User $admin;

    private User $cit;

    private CarouselItem $citSlide;

    private CarouselItem $cbaSlide;

    private CarouselItem $mainSlide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        Department::query()->forceCreate(['id' => 11, 'name' => 'CBA', 'slug' => 'cba']);
        $this->admin = User::factory()->superadmin()->create();
        $this->cit = User::factory()->forDepartment(10)->create();
        $slide = fn (int $order, ?int $department) => CarouselItem::query()->forceCreate([
            'image_path' => $this->stored("carousels/s{$order}.webp"), 'order' => $order, 'department_id' => $department, 'show_on_main' => true,
        ])->fresh();
        $this->mainSlide = $slide(1, null);
        $this->citSlide = $slide(2, 10);
        $this->cbaSlide = $slide(3, 11);
    }

    private function action(?User $user, string $name, array $input = []): TestResponse
    {
        return ($user ? $this->actingAs($user) : $this)->postJson("/_actions/{$name}", $input);
    }

    private function form(User $user, string $name, array $input): TestResponse
    {
        return $this->actingAs($user)->post("/_actions/{$name}", $input, ['Accept' => 'application/json']);
    }

    private function overlay(array $layers): string
    {
        return Overlay::serializeOverlay($layers);
    }

    public function test_signed_out_calls_are_unauthorized(): void
    {
        foreach (['create', 'update', 'placement', 'duplicate', 'reorder', 'delete'] as $name) {
            $this->action(null, "carousels.{$name}")->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHORIZED');
        }
    }

    public function test_a_department_user_cannot_touch_slides_of_another_department_or_the_main_page(): void
    {
        foreach ([$this->cbaSlide, $this->mainSlide] as $slide) {
            $before = $slide->fresh()->only(['overlay', 'department_id', 'show_on_main', 'order']);
            $this->form($this->cit, 'carousels.update', ['id' => $slide->id, 'overlay' => $this->overlay([])])->assertStatus(403);
            $this->action($this->cit, 'carousels.placement', ['id' => $slide->id, 'showOnMain' => false])->assertStatus(403);
            $this->action($this->cit, 'carousels.duplicate', ['id' => $slide->id])->assertStatus(403);
            $this->action($this->cit, 'carousels.delete', ['id' => $slide->id])->assertStatus(403);
            $this->action($this->cit, 'carousels.reorder', ['ids' => [$slide->id, $this->citSlide->id]])->assertStatus(403);
            $this->assertSame($before, $slide->fresh()->only(['overlay', 'department_id', 'show_on_main', 'order']));
        }
        $this->assertSame(3, CarouselItem::query()->count());
    }

    public function test_a_missing_slide_is_not_found(): void
    {
        $this->action($this->admin, 'carousels.delete', ['id' => 9999])->assertStatus(404);
        $this->action($this->admin, 'carousels.reorder', ['ids' => [$this->mainSlide->id, 9999]])->assertStatus(404);
        $this->form($this->admin, 'carousels.update', ['id' => 9999, 'overlay' => $this->overlay([])])->assertStatus(404);
    }

    public function test_update_validates_the_design_and_derives_the_legacy_columns(): void
    {
        $heading = [...Overlay::defaultLayer('text'), 'id' => 'h', 'text' => 'Welcome'];
        $button = [...Overlay::defaultLayer('button'), 'id' => 'b', 'href' => '/posts'];
        $response = $this->form($this->cit, 'carousels.update', ['id' => $this->citSlide->id, 'overlay' => $this->overlay([$heading, $button])])->assertOk();
        $response->assertJsonPath('data.slide.id', $this->citSlide->id)->assertJsonPath('data.slide.title', 'Welcome')->assertJsonPath('data.slide.layers.0.id', 'h');
        $row = $this->citSlide->fresh();
        $this->assertSame(['Welcome', '/posts'], [$row->title, $row->link_url]);
        $this->assertEquals(json_decode($this->overlay([$heading, $button])), json_decode($row->overlay));

        $bad = $this->form($this->cit, 'carousels.update', ['id' => $this->citSlide->id, 'overlay' => $this->overlay([[...$heading, 'color' => '#ff0000']])]);
        $bad->assertStatus(400)->assertJsonPath('error.message', 'Invalid option: expected one of "white"|"gold"|"navy"|"navy-deep"|"sky"|"foreground"|"surface" (layer 1)');
        $this->form($this->cit, 'carousels.update', ['id' => $this->citSlide->id, 'overlay' => '{nope'])->assertStatus(400)->assertJsonPath('error.message', 'The slide design is not valid JSON.');
        $this->assertEquals(json_decode($this->overlay([$heading, $button])), json_decode($this->citSlide->fresh()->overlay));
    }

    public function test_an_oversize_design_is_rejected_as_an_input_error(): void
    {
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => str_repeat('x', 60_001)])
            ->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError')->assertJsonPath('error.fields.overlay.0', 'The slide design is too large.');
    }

    public function test_update_sets_removes_and_validates_the_video(): void
    {
        $youtube = 'https://youtu.be/dQw4w9WgXcQ';
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([]), 'video' => $youtube])->assertOk()
            ->assertJsonPath('data.slide.video', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')->assertJsonPath('data.slide.videoKind', 'youtube');
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $this->mainSlide->fresh()->video_url);

        // Omitted keeps the video, '' removes it.
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([])])->assertOk();
        $this->assertNotNull($this->mainSlide->fresh()->video_url);
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([]), 'video' => ''])->assertOk()
            ->assertJsonPath('data.slide.video', '')->assertJsonPath('data.slide.videoKind', null);
        $this->assertNull($this->mainSlide->fresh()->video_url);

        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([]), 'video' => 'https://vimeo.com/123456789'])
            ->assertStatus(400)->assertJsonPath('error.message', 'Use a YouTube link or upload an mp4 or webm video.');
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([]), 'video' => '/storage/videos/ffffffffffffffffffffffffffffffff.mp4'])
            ->assertStatus(400)->assertJsonPath('error.message', 'The uploaded video was not found. Upload it again.');
    }

    public function test_update_with_an_uploaded_video_adopts_it(): void
    {
        $key = $this->stored();
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => $this->overlay([]), 'video' => "/storage/{$key}"])->assertOk()
            ->assertJsonPath('data.slide.video', "/storage/{$key}")->assertJsonPath('data.slide.videoKind', 'file');
        $this->assertSame($key, $this->mainSlide->fresh()->video_path);
        // The same upload cannot be adopted by a second slide.
        $this->form($this->admin, 'carousels.update', ['id' => $this->cbaSlide->id, 'overlay' => $this->overlay([]), 'video' => "/storage/{$key}"])
            ->assertStatus(400)->assertJsonPath('error.message', 'This video is already used elsewhere. Upload it again for this slide.');
    }

    public function test_create_forces_a_department_users_own_department_and_goes_last(): void
    {
        $response = $this->form($this->cit, 'carousels.create', [
            'image' => UploadedFile::fake()->image('slide.png', 160, 90), 'departmentId' => '11', 'showOnMain' => 'false',
            'overlay' => $this->overlay([[...Overlay::defaultLayer('text'), 'text' => 'Hello']]),
        ])->assertOk();
        $response->assertJsonPath('data.slide.departmentId', 10)->assertJsonPath('data.slide.showOnMain', false)->assertJsonPath('data.slide.order', 4)->assertJsonPath('data.slide.title', 'Hello');
        $row = CarouselItem::query()->find($response->json('data.slide.id'));
        $this->assertTrue($this->has($row->image_path));
    }

    public function test_superadmin_picks_the_department_and_it_must_exist(): void
    {
        $image = fn () => UploadedFile::fake()->image('slide.png', 160, 90);
        $this->form($this->admin, 'carousels.create', ['image' => $image(), 'departmentId' => '11', 'showOnMain' => 'true'])->assertOk()->assertJsonPath('data.slide.departmentId', 11);
        $this->form($this->admin, 'carousels.create', ['image' => $image(), 'showOnMain' => 'true'])->assertOk()->assertJsonPath('data.slide.departmentId', null);
        $this->form($this->admin, 'carousels.create', ['image' => $image(), 'departmentId' => '999', 'showOnMain' => 'true'])
            ->assertStatus(400)->assertJsonPath('error.message', 'The selected department does not exist.');
    }

    public function test_create_needs_a_valid_image(): void
    {
        $this->form($this->admin, 'carousels.create', ['showOnMain' => 'true'])->assertStatus(400)->assertJsonPath('error.fields.image.0', 'Choose an image file.');
        $this->form($this->admin, 'carousels.create', ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'showOnMain' => 'true'])
            ->assertStatus(400)->assertJsonPath('error.fields.image.0', 'The file must be a JPG, PNG, WebP, GIF or AVIF image.');
        $this->assertSame(3, CarouselItem::query()->count());
    }

    public function test_a_form_without_show_on_main_reads_as_false(): void
    {
        // As Astro's form actions: an absent boolean field is an unchecked box.
        $this->form($this->admin, 'carousels.create', ['image' => UploadedFile::fake()->image('a.png', 16, 9)])
            ->assertOk()->assertJsonPath('data.slide.showOnMain', false);
    }

    public function test_placement_changes_where_a_slide_is_shown(): void
    {
        $this->action($this->admin, 'carousels.placement', ['id' => $this->mainSlide->id, 'showOnMain' => false, 'departmentId' => 11])->assertOk()
            ->assertJsonPath('data.slide.showOnMain', false)->assertJsonPath('data.slide.departmentId', 11);
        // Absent departmentId keeps it; an explicit null moves the slide to the main page.
        $this->action($this->admin, 'carousels.placement', ['id' => $this->mainSlide->id, 'showOnMain' => true])->assertOk()->assertJsonPath('data.slide.departmentId', 11);
        $this->action($this->admin, 'carousels.placement', ['id' => $this->mainSlide->id, 'departmentId' => null])->assertOk()
            ->assertJsonPath('data.slide.departmentId', null)->assertJsonPath('data.slide.showOnMain', true);
        $this->action($this->admin, 'carousels.placement', ['id' => $this->mainSlide->id, 'departmentId' => 999])->assertStatus(400);
    }

    public function test_a_department_user_cannot_move_a_slide_to_another_department(): void
    {
        $this->action($this->cit, 'carousels.placement', ['id' => $this->citSlide->id, 'departmentId' => 11, 'showOnMain' => false])->assertOk()
            ->assertJsonPath('data.slide.departmentId', 10)->assertJsonPath('data.slide.showOnMain', false);
        $this->action($this->cit, 'carousels.placement', ['id' => $this->citSlide->id, 'departmentId' => null])->assertOk()->assertJsonPath('data.slide.departmentId', 10);
    }

    public function test_duplicate_copies_the_slide_right_after_the_original(): void
    {
        $response = $this->action($this->cit, 'carousels.duplicate', ['id' => $this->citSlide->id])->assertOk();
        $copy = CarouselItem::query()->find($response->json('data.slide.id'));
        $this->assertSame([3, 10], [$copy->order, $copy->department_id]);
        $this->assertNotSame($this->citSlide->image_path, $copy->image_path);
        $this->assertTrue($this->has($copy->image_path));
        $this->assertSame(4, $this->cbaSlide->fresh()->order);
    }

    public function test_reorder_assigns_the_slots_of_the_listed_slides_in_their_new_sequence(): void
    {
        $response = $this->action($this->admin, 'carousels.reorder', ['ids' => [$this->cbaSlide->id, $this->mainSlide->id, $this->citSlide->id]])->assertOk();
        $this->assertEquals([(string) $this->cbaSlide->id => 1, (string) $this->mainSlide->id => 2, (string) $this->citSlide->id => 3], $response->json('data.orders'));
        $this->assertSame([1, 2, 3], [$this->cbaSlide->fresh()->order, $this->mainSlide->fresh()->order, $this->citSlide->fresh()->order]);

        $this->action($this->cit, 'carousels.reorder', ['ids' => [$this->citSlide->id]])->assertOk()->assertJsonPath('data.orders.'.$this->citSlide->id, 3);
    }

    public function test_reorder_rejects_duplicates_and_bad_lists(): void
    {
        $this->action($this->admin, 'carousels.reorder', ['ids' => [$this->mainSlide->id, $this->mainSlide->id]])->assertStatus(400)->assertJsonPath('error.message', 'Duplicate slides in the order.');
        $this->action($this->admin, 'carousels.reorder', ['ids' => []])->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError');
        $this->action($this->admin, 'carousels.reorder', ['ids' => range(1, 501)])->assertStatus(400)->assertJsonPath('error.fields.ids.0', 'Too big: expected array to have <=500 items');
    }

    public function test_delete_removes_the_slide_and_its_photo(): void
    {
        $this->action($this->cit, 'carousels.delete', ['id' => $this->citSlide->id])->assertOk()->assertJsonPath('data.id', $this->citSlide->id);
        $this->assertNull(CarouselItem::query()->find($this->citSlide->id));
        $this->assertFalse($this->has($this->citSlide->image_path));
        $this->assertTrue($this->has($this->cbaSlide->image_path));
    }

    public function test_an_empty_or_missing_design_is_a_bad_request_not_a_required_error(): void
    {
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id, 'overlay' => ''])
            ->assertStatus(400)->assertJsonPath('error.code', 'BAD_REQUEST')->assertJsonPath('error.message', 'The slide design is not valid JSON.');
        $this->form($this->admin, 'carousels.update', ['id' => $this->mainSlide->id])
            ->assertStatus(400)->assertJsonPath('error.message', 'The slide design is not valid JSON.');
    }

    public function test_reorder_rejects_numeric_string_ids(): void
    {
        $this->action($this->admin, 'carousels.reorder', ['ids' => [(string) $this->mainSlide->id]])->assertStatus(400)->assertJsonPath('error.type', 'ActionInputError');
    }
}
