<?php

namespace Tests\Feature\Storage;

use App\Models\PendingUpload;
use App\Storage\PendingUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingUploadsTest extends TestCase
{
    use RefreshDatabase;

    private const HOUR = 60 * 60 * 1000;

    public function test_it_is_claimed_once_by_its_owner_before_it_expires(): void
    {
        $user = $this->actingAsSuperadmin();
        $key = 'videos/'.str_repeat('1', 32).'.mp4';
        PendingUploads::registerPendingUpload($key, $user->id);
        $this->assertFalse(PendingUploads::claimPendingUpload($key, $user->id + 1));
        $this->assertTrue(PendingUploads::ownsPendingUpload($key, $user->id));
        $this->assertTrue(PendingUploads::claimPendingUpload($key, $user->id));
        $this->assertFalse(PendingUploads::claimPendingUpload($key, $user->id));
    }

    public function test_it_cannot_be_claimed_after_expiry(): void
    {
        $user = $this->actingAsSuperadmin();
        $key = 'videos/'.str_repeat('2', 32).'.mp4';
        PendingUploads::registerPendingUpload($key, $user->id, (int) (microtime(true) * 1000) - 2 * self::HOUR);
        $this->assertFalse(PendingUploads::ownsPendingUpload($key, $user->id));
        $this->assertFalse(PendingUploads::claimPendingUpload($key, $user->id));
    }

    public function test_it_sweeps_long_expired_rows_and_keeps_fresh_and_recently_expired_ones_returning_the_count(): void
    {
        $user = $this->actingAsSuperadmin();
        [$old, $recent, $fresh] = array_map(fn (string $c) => 'videos/'.str_repeat($c, 32).'.mp4', ['3', '4', '5']);
        $now = (int) (microtime(true) * 1000);
        PendingUploads::registerPendingUpload($old, $user->id, $now - 3 * self::HOUR);
        // Expired a minute ago: inside the sweep grace, so a racing claim never reads a swept row.
        PendingUploads::registerPendingUpload($recent, $user->id, $now - self::HOUR - 60_000);
        PendingUploads::registerPendingUpload($fresh, $user->id, $now);

        $this->assertSame(1, PendingUploads::countExpiredPendingUploads($now));
        $this->assertSame(1, PendingUploads::sweepPendingUploads($now));
        $left = PendingUpload::query()->pluck('key')->all();
        $this->assertNotContains($old, $left);
        $this->assertEqualsCanonicalizing([$recent, $fresh], $left);
    }
}
