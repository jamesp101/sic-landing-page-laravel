<?php

namespace Tests\Unit\Platform;

use App\Http\RequestPolicyRules as Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `fixtures/route-classes.json` is read by `resources/js/lib/parity/route-classes.parity.test.ts` too: one list of route class cases for both sides. */
final class RouteClassParityTest extends TestCase
{
    /** @return array<string, array{array{path: string, publicPage: bool, private: bool, asset: bool}}> */
    public static function cases(): array
    {
        $cases = json_decode((string) file_get_contents(__DIR__.'/fixtures/route-classes.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $named = [];
        foreach ($cases as $case) {
            $named[$case['path']] = [$case];
        }

        return $named;
    }

    #[DataProvider('cases')]
    public function test_route_classes_match_the_fixture(array $case): void
    {
        $this->assertSame($case['publicPage'], Rules::isPublicPage($case['path']), 'isPublicPage');
        $this->assertSame($case['private'], Rules::isPrivatePath($case['path']), 'isPrivatePath');
        $this->assertSame($case['asset'], Rules::isAssetPath($case['path']), 'isAssetPath');
    }
}
