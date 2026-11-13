<?php

namespace Tests\Unit\OrgChart;

use App\OrgChart\OrgTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `fixtures/org-tree.json` is read by `resources/js/lib/parity/org-tree.parity.test.ts` too: one list of inputs and outcomes for the four rules both sides implement. */
final class OrgTreeParityTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/org-tree.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Expands `[id, parentId, order]` triples (or the name of a shared list) into members. */
    private static function members(array|string $spec): array
    {
        $triples = is_string($spec) ? self::fixture()[$spec] : $spec;

        return array_map(fn (array $t): array => ['id' => $t[0], 'name' => "M{$t[0]}", 'role' => "Role {$t[0]}", 'parentId' => $t[1], 'order' => $t[2], 'imageUrl' => null], $triples);
    }

    private static function shape(array $nodes): array
    {
        return array_map(fn (array $n) => $n['children'] ? [$n['member']['id'], self::shape($n['children'])] : $n['member']['id'], $nodes);
    }

    private static function cases(string $rule): array
    {
        $named = [];
        foreach (self::fixture()[$rule] as $case) {
            $named[$case['name']] = [$case];
        }

        return $named;
    }

    public static function buildCases(): array
    {
        return self::cases('buildOrgTree');
    }

    public static function wouldCycleCases(): array
    {
        return self::cases('wouldCycle');
    }

    public static function reparentCases(): array
    {
        return self::cases('reparentOnDelete');
    }

    public static function placementCases(): array
    {
        return self::cases('placementsError');
    }

    #[DataProvider('buildCases')]
    public function test_build_org_tree(array $case): void
    {
        $this->assertSame($case['shape'], self::shape(OrgTree::buildOrgTree(self::members($case['members']))));
    }

    #[DataProvider('wouldCycleCases')]
    public function test_would_cycle(array $case): void
    {
        $this->assertSame($case['result'], OrgTree::wouldCycle(self::members($case['members']), $case['id'], $case['parentId']));
    }

    #[DataProvider('reparentCases')]
    public function test_reparent_on_delete(array $case): void
    {
        $this->assertSame($case['placements'], OrgTree::reparentOnDelete(self::members($case['members']), $case['id']));
    }

    #[DataProvider('placementCases')]
    public function test_placements_error(array $case): void
    {
        $this->assertSame($case['error'], OrgTree::placementsError(self::members($case['members']), $case['placements']));
    }
}
