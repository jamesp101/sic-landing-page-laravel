<?php

namespace Tests\Unit\Admin\Trees;

use App\Admin\NavLinkRules;
use App\Models\NavigationLink;
use App\Support\RequestError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Port of nav-link-rules.test.ts. */
class NavLinkRulesTest extends TestCase
{
    use RefreshDatabase;

    private function link(string $label, ?int $parentId = null): NavigationLink
    {
        return NavigationLink::query()->forceCreate(['label' => $label, 'url' => "/{$label}", 'order' => 0, 'is_active' => true, 'parent_id' => $parentId]);
    }

    private function message(callable $call): ?string
    {
        try {
            $call();
        } catch (RequestError $error) {
            return $error->getMessage();
        }

        return null;
    }

    public function test_it_accepts_no_parent_and_an_existing_top_level_link(): void
    {
        $top = $this->link('top');
        NavLinkRules::assertValidParent(null);
        NavLinkRules::assertValidParent($top->id);
        $this->addToAssertionCount(2);
    }

    public function test_it_rejects_itself_a_missing_link_and_a_sub_link_as_parent(): void
    {
        $top = $this->link('top');
        $child = $this->link('child', $top->id);
        $this->assertMatchesRegularExpression('/own parent/', $this->message(fn () => NavLinkRules::assertValidParent($top->id, $top->id)));
        $this->assertMatchesRegularExpression('/does not exist/', $this->message(fn () => NavLinkRules::assertValidParent(9999)));
        $this->assertMatchesRegularExpression('/top-level/', $this->message(fn () => NavLinkRules::assertValidParent($child->id)));
    }

    public function test_it_rejects_turning_a_link_that_has_sub_links_into_a_sub_link(): void
    {
        $top = $this->link('top');
        $this->link('child', $top->id);
        $other = $this->link('other');
        $this->assertMatchesRegularExpression('/sub-links/', $this->message(fn () => NavLinkRules::assertValidParent($other->id, $top->id)));
    }

    public function test_it_fails_as_a_bad_request_error(): void
    {
        try {
            NavLinkRules::assertValidParent(9999);
            $this->fail('Expected a RequestError.');
        } catch (RequestError $error) {
            $this->assertSame([RequestError::BAD_REQUEST, 400], [$error->errorCode, $error->status()]);
        }
    }
}
