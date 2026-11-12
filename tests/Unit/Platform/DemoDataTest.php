<?php

namespace Tests\Unit\Platform;

use Database\Seeders\DemoData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DemoDataTest extends TestCase
{
    public function test_a_sqlite_or_loopback_database_is_local(): void
    {
        DemoData::assertLocalDatabase(['driver' => 'sqlite'], false);
        DemoData::assertLocalDatabase(['driver' => 'mariadb', 'host' => '127.0.0.1'], false);
        DemoData::assertLocalDatabase(['driver' => 'mysql', 'host' => 'localhost'], false);
        DemoData::assertLocalDatabase(['driver' => 'mysql', 'host' => '::1'], false);
        $this->addToAssertionCount(1);
    }

    public function test_a_remote_database_is_refused_unless_allowed(): void
    {
        DemoData::assertLocalDatabase(['driver' => 'mysql', 'host' => 'db.example.org'], true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to seed the database on db.example.org. Set SEED_ALLOW_REMOTE=1');
        DemoData::assertLocalDatabase(['driver' => 'mysql', 'host' => 'db.example.org'], false);
    }

    public function test_a_database_without_a_host_is_refused(): void
    {
        $this->expectExceptionMessage('Refusing to seed a database that is not local.');
        DemoData::assertLocalDatabase(['driver' => 'mysql'], false);
    }

    public function test_rows_refer_to_each_other_by_existing_keys(): void
    {
        foreach (DemoData::POSTS as $post) {
            $this->assertArrayHasKey($post['author'], DemoData::USERS);
            $this->assertTrue($post['department'] === null || isset(DemoData::DEPARTMENTS[$post['department']]));
        }
        $seen = [];
        foreach (DemoData::MEMBERS as $key => $member) {
            $this->assertTrue($member['parent'] === null || isset($seen[$member['parent']]), "{$key} parent listed first");
            $seen[$key] = true;
        }
    }
}
