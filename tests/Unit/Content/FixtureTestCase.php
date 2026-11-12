<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use PHPUnit\Framework\TestCase;

/** Base for tests that compare against fixtures generated from the TS implementation (`fixtures/*.json`). */
abstract class FixtureTestCase extends TestCase
{
    /** @return list<array<string, mixed>> */
    protected static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Inputs too big to store in a fixture file are rebuilt here. */
    protected static function input(array $case): ?string
    {
        if ($case['input'] !== '@large') {
            return $case['input'];
        }

        return match ($case['name']) {
            'too large' => '{"type":"sic-blocks","version":1,"blocks":[],"pad":"'.str_repeat('x', 400_000).'"}',
            'markdown too big' => json_encode(['type' => 'sic-blocks', 'version' => 1, 'blocks' => [['id' => 'big', 'type' => 'markdown', 'props' => (object) ['source' => str_repeat('x', 100_001)]]]]),
        };
    }

    /** @return array<string, array{array<string, mixed>}> */
    protected static function cases(string $name): array
    {
        $cases = [];
        foreach (self::fixture($name) as $index => $case) {
            $cases[($case['name'] ?? (string) $index).' #'.$index] = [$case];
        }

        return $cases;
    }
}
