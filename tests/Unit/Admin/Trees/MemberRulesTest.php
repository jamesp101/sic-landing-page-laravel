<?php

namespace Tests\Unit\Admin\Trees;

use App\Admin\MemberRules;
use App\Models\Department;
use App\Models\Member;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Port of member-rules.test.ts. */
class MemberRulesTest extends TestCase
{
    use RefreshDatabase;

    private function department(string $name): Department
    {
        return Department::query()->forceCreate(['name' => $name, 'slug' => strtolower($name)]);
    }

    private function member(int $departmentId, string $name, ?int $parentId = null): Member
    {
        return Member::query()->forceCreate(['department_id' => $departmentId, 'name' => $name, 'role' => 'Role', 'order' => 0, 'parent_id' => $parentId]);
    }

    private function message(callable $call): ?string
    {
        try {
            $call();
        } catch (RequestError $error) {
            $this->assertSame(RequestError::BAD_REQUEST, $error->errorCode);

            return $error->getMessage();
        }

        return null;
    }

    public function test_it_accepts_no_parent_and_a_member_of_the_same_department(): void
    {
        $cit = $this->department('CIT');
        $boss = $this->member($cit->id, 'Boss');
        MemberRules::assertValidParent($cit->id, null);
        MemberRules::assertValidParent($cit->id, $boss->id);
        $this->addToAssertionCount(2);
    }

    public function test_it_rejects_another_departments_member_and_a_missing_one(): void
    {
        $cit = $this->department('CIT');
        $stranger = $this->member($this->department('CBA')->id, 'Stranger');
        $expected = 'The selected parent does not belong to this department.';
        $this->assertSame($expected, $this->message(fn () => MemberRules::assertValidParent($cit->id, $stranger->id)));
        $this->assertSame($expected, $this->message(fn () => MemberRules::assertValidParent($cit->id, 9999)));
    }

    public function test_it_rejects_a_parent_that_is_the_member_or_one_of_its_subordinates(): void
    {
        $cit = $this->department('CIT');
        $boss = $this->member($cit->id, 'Boss');
        $report = $this->member($cit->id, 'Report', $boss->id);
        $this->assertMatchesRegularExpression('/itself or to one of/', $this->message(fn () => MemberRules::assertValidParent($cit->id, $boss->id, $boss->id)));
        $this->assertMatchesRegularExpression('/subordinates/', $this->message(fn () => MemberRules::assertValidParent($cit->id, $report->id, $boss->id)));
        MemberRules::assertValidParent($cit->id, $boss->id, $report->id);
        $this->addToAssertionCount(1);
    }

    public function test_tree_lists_the_departments_members_as_ordering_rows(): void
    {
        $cit = $this->department('CIT');
        $boss = $this->member($cit->id, 'Boss');
        $report = $this->member($cit->id, 'Report', $boss->id);
        $this->member($this->department('CBA')->id, 'Other');
        $this->assertEquals([
            ['id' => $boss->id, 'parentId' => null, 'order' => 0],
            ['id' => $report->id, 'parentId' => $boss->id, 'order' => 0],
        ], MemberRules::tree($cit->id));
    }
}
