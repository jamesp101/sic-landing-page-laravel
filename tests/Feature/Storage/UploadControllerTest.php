<?php

namespace Tests\Feature\Storage;

use App\Models\PendingUpload;
use App\Storage\PendingUploads;
use App\Storage\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\Storage\UsesUploadsDisk;

class UploadControllerTest extends TestCase
{
    use RefreshDatabase;
    use UsesUploadsDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalUploads();
    }

    private function putBody(string $uri, string $body)
    {
        return $this->call('PUT', $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'video/mp4'], $body);
    }

    private function issueVideo(string $name = 'clip.mp4', int $size = 10): array
    {
        return $this->postJson('/api/admin/uploads/video', ['fileName' => $name, 'contentType' => 'video/mp4', 'size' => $size])->assertOk()->json();
    }

    public function test_every_route_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/admin/uploads/image')->assertUnauthorized();
        $this->postJson('/api/admin/uploads/direct', [])->assertUnauthorized();
        $this->postJson('/api/admin/uploads/video', [])->assertUnauthorized();
        $this->putJson('/api/admin/uploads/video')->assertUnauthorized();
        $this->postJson('/api/admin/uploads/video/complete', [])->assertUnauthorized();
    }

    // ---- image -------------------------------------------------------------------------------------------------

    public function test_image_stores_a_webp_and_returns_its_url(): void
    {
        $this->actingAsSuperadmin();
        $response = $this->post('/api/admin/uploads/image', [
            'image' => $this->upload($this->imageBytes(40, 30), 'photo.png', 'image/png'),
            'kind' => 'layer',
        ], ['Accept' => 'application/json'])->assertOk();
        $url = $response->json('url');
        $this->assertMatchesRegularExpression('#^/storage/carousels/[0-9a-f]{32}\.webp$#', $url);
        $this->get($url)->assertOk();
    }

    public function test_image_defaults_to_the_content_kind_and_rejects_an_unknown_kind_and_a_bad_file(): void
    {
        $this->actingAsSuperadmin();
        $png = $this->upload($this->imageBytes(), 'photo.png', 'image/png');
        $this->post('/api/admin/uploads/image', ['image' => $png], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('url', fn (string $url) => str_starts_with($url, '/storage/posts/inline/'));

        $this->post('/api/admin/uploads/image', ['image' => $png, 'kind' => 'avatar'], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertJson(['message' => 'Unknown image kind.']);
        $this->post('/api/admin/uploads/image', [], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertJson(['message' => 'Choose an image file.']);
        $this->post('/api/admin/uploads/image', ['image' => $this->upload('<svg/>', 'a.svg', 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertJson(['message' => 'The file must be a JPG, PNG, WebP, GIF or AVIF image.']);
    }

    public function test_image_is_rate_limited_per_user(): void
    {
        $this->actingAsSuperadmin();
        $png = $this->upload($this->imageBytes(8, 8), 'tiny.png', 'image/png');
        for ($i = 0; $i < 60; $i++) {
            $this->post('/api/admin/uploads/image', ['image' => $png], ['Accept' => 'application/json'])->assertOk();
        }
        $this->post('/api/admin/uploads/image', ['image' => $png], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJson(['message' => 'Too many uploads. Try again in a few minutes.']);
    }

    // ---- direct (s3 only) --------------------------------------------------------------------------------------

    public function test_direct_is_not_available_on_the_local_driver(): void
    {
        $this->actingAsSuperadmin();
        $this->postJson('/api/admin/uploads/direct', ['fileName' => 'a.pdf', 'contentType' => 'application/pdf', 'size' => 5])
            ->assertNotFound()->assertJson(['message' => 'Direct uploads are not enabled.']);
    }

    public function test_direct_issues_a_download_key_and_a_presigned_url_recorded_for_the_user(): void
    {
        $this->useS3Config();
        $user = $this->actingAsSuperadmin();
        $response = $this->postJson('/api/admin/uploads/direct', ['fileName' => 'Handbook.PDF', 'contentType' => 'application/pdf', 'size' => 1234])->assertOk();
        $key = $response->json('key');
        $this->assertMatchesRegularExpression('#^downloads/[0-9a-f]{32}\.pdf$#', $key);
        $url = parse_url($response->json('url'));
        $this->assertStringEndsWith("/site/{$key}", $url['path']);
        parse_str($url['query'], $query);
        $this->assertSame('content-length;content-type;host', $query['X-Amz-SignedHeaders']);
        $this->assertTrue(PendingUploads::ownsPendingUpload($key, $user->id));
    }

    public function test_direct_validates_the_request_type_and_size(): void
    {
        $this->useS3Config();
        $this->actingAsSuperadmin();
        $this->postJson('/api/admin/uploads/direct', ['fileName' => 'a.html', 'contentType' => 'text/html', 'size' => 5])
            ->assertStatus(422)->assertJson(['message' => 'This file type is not allowed.']);
        $this->postJson('/api/admin/uploads/direct', ['fileName' => 'a.mp4', 'contentType' => 'video/mp4', 'size' => 5])
            ->assertStatus(422)->assertJson(['message' => 'This file type is not allowed.']);
        $this->postJson('/api/admin/uploads/direct', ['fileName' => 'a.pdf', 'contentType' => 'application/pdf', 'size' => 20 * 1024 * 1024 + 1])
            ->assertStatus(422)->assertJson(['message' => 'The file must not be larger than 20 MB.']);
        $this->postJson('/api/admin/uploads/direct', ['fileName' => '', 'contentType' => 'application/pdf', 'size' => 5])
            ->assertStatus(400)->assertJson(['message' => 'A file name is required.']);
        $this->postJson('/api/admin/uploads/direct', ['fileName' => 'a.pdf', 'contentType' => 'application/pdf', 'size' => 0])
            ->assertStatus(400)->assertJson(['message' => 'Choose a file to upload.']);
        $this->call('POST', '/api/admin/uploads/direct', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '{nope')
            ->assertStatus(400)->assertJson(['message' => 'Expected a JSON body.']);
        $this->assertSame(0, PendingUpload::query()->count());
    }

    // ---- video -------------------------------------------------------------------------------------------------

    public function test_video_issues_a_key_and_the_local_put_url(): void
    {
        $user = $this->actingAsSuperadmin();
        $body = $this->issueVideo('Clip.MP4', 10);
        $this->assertMatchesRegularExpression('#^videos/[0-9a-f]{32}\.mp4$#', $body['key']);
        $this->assertSame('video/mp4', $body['contentType']);
        $this->assertSame('/api/admin/uploads/video?'.http_build_query(['key' => $body['key'], 'size' => '10']), $body['url']);
        $this->assertTrue(PendingUploads::ownsPendingUpload($body['key'], $user->id));
    }

    public function test_video_issues_a_presigned_url_on_s3(): void
    {
        $this->useS3Config();
        $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.webm', 10);
        $this->assertStringEndsWith('.webm', $body['key']);
        $this->assertStringContainsString("/site/{$body['key']}?", $body['url']);
        $this->assertStringContainsString('X-Amz-Signature=', $body['url']);
    }

    public function test_video_rejects_other_types_and_too_large_videos_without_recording_a_key(): void
    {
        $this->actingAsSuperadmin();
        $this->postJson('/api/admin/uploads/video', ['fileName' => 'a.mov', 'contentType' => 'video/quicktime', 'size' => 5])
            ->assertStatus(422)->assertJson(['message' => 'Choose an MP4 or WebM video.']);
        $this->postJson('/api/admin/uploads/video', ['fileName' => 'a.mp4', 'contentType' => 'video/mp4', 'size' => 100 * 1024 * 1024 + 1])
            ->assertStatus(422)->assertJson(['message' => 'The video must not be larger than 100 MB.']);
        $this->assertSame(0, PendingUpload::query()->count());
    }

    public function test_video_bytes_streams_the_body_to_disk_for_the_key_owner(): void
    {
        $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.mp4', 10);
        $this->putBody($body['url'], '0123456789')->assertNoContent();
        $this->assertSame('0123456789', Storage::read($body['key'])->contents());
    }

    public function test_video_bytes_refuses_a_body_past_the_announced_size_and_removes_it(): void
    {
        $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.mp4', 10);
        $this->putBody($body['url'], str_repeat('x', 11))->assertStatus(400)
            ->assertJson(['message' => 'The video is larger than the size it was announced with.']);
        $this->assertNull(Storage::head($body['key']));
        $this->putBody($body['url'], '1234')->assertStatus(400)->assertJson(['message' => 'The upload was incomplete. Try again.']);
        $this->assertNull(Storage::head($body['key']));
    }

    public function test_video_bytes_is_for_the_key_owner_only(): void
    {
        $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.mp4', 10);
        $this->actingAsSuperadmin();
        $this->putBody($body['url'], '0123456789')->assertStatus(400)->assertJson(['message' => 'Invalid uploaded file.']);
        $this->assertNull(Storage::head($body['key']));
    }

    public function test_video_bytes_is_not_available_on_s3(): void
    {
        $this->useS3Config();
        $this->actingAsSuperadmin();
        $this->putBody('/api/admin/uploads/video?key=videos/'.str_repeat('a', 32).'.mp4&size=10', 'x')->assertNotFound();
    }

    public function test_video_complete_claims_the_upload_and_returns_the_app_url(): void
    {
        $user = $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.mp4', 10);
        $this->putBody($body['url'], '0123456789')->assertNoContent();

        $this->postJson('/api/admin/uploads/video/complete', ['key' => $body['key']])
            ->assertOk()->assertExactJson(['url' => "/storage/{$body['key']}"]);
        $this->assertFalse(PendingUploads::ownsPendingUpload($body['key'], $user->id));
        $this->assertSame('video/mp4', $this->get("/storage/{$body['key']}")->headers->get('Content-Type'));

        $this->postJson('/api/admin/uploads/video/complete', ['key' => $body['key']])
            ->assertStatus(400)->assertJson(['message' => 'Invalid uploaded file.']);
        $this->postJson('/api/admin/uploads/video/complete', ['key' => 5])
            ->assertStatus(400)->assertJson(['message' => 'Expected a JSON body with a key.']);
    }

    public function test_video_complete_reports_a_missing_object_without_burning_the_key(): void
    {
        $user = $this->actingAsSuperadmin();
        $body = $this->issueVideo('clip.mp4', 10);
        $this->postJson('/api/admin/uploads/video/complete', ['key' => $body['key']])
            ->assertStatus(400)->assertJson(['message' => 'The uploaded video was not found. Upload it again.']);
        $this->assertTrue(PendingUploads::ownsPendingUpload($body['key'], $user->id));
    }
}
