<?php

namespace Tests\Feature\Admin\Core;

use App\Actions\Access;
use App\Actions\ActionRegistry;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The access declared on each action (`#[Action(Access::…)]`) and its enforcement before the action body. */
class ActionAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_action_method_declares_its_access(): void
    {
        $undeclared = array_keys(array_filter(ActionRegistry::all(), fn (?Access $access): bool => $access === null));

        $this->assertSame([], $undeclared, 'Add #[Action(Access::…)] to these action methods.');
        $this->assertNotEmpty(ActionRegistry::all());
    }

    public function test_the_public_actions_are_exactly_the_declared_ones(): void
    {
        $this->assertEqualsCanonicalizing(
            ['auth.forgotPassword', 'auth.login', 'auth.resetPassword', 'contact.send'],
            ActionRegistry::publicNames(),
        );
    }

    public function test_helpers_and_unknown_names_are_not_dispatchable(): void
    {
        foreach (['posts.input', 'posts.nope', 'nope.create', 'posts', 'posts.__construct'] as $name) {
            $this->assertNull(ActionRegistry::find($name), $name);
            $this->assertFalse(ActionRegistry::isPublic($name), $name);
        }
    }

    public function test_superadmin_actions_answer_403_to_a_department_user_before_validating_input(): void
    {
        Department::query()->forceCreate(['id' => 10, 'name' => 'CIT', 'slug' => 'cit']);
        $cit = User::factory()->forDepartment(10)->create();
        $superadminActions = array_keys(array_filter(ActionRegistry::all(), fn (?Access $access): bool => $access === Access::Superadmin));
        $this->assertContains('departments.create', $superadminActions);
        $this->assertContains('settings.updateLanding', $superadminActions);

        foreach ($superadminActions as $name) {
            // An empty body is invalid for every one of them: it would be a 400 if the body ran first.
            $this->actingAs($cit)->postJson("/_actions/{$name}", [])
                ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        }
    }

    public function test_signed_in_only_actions_answer_401_to_a_signed_out_visitor(): void
    {
        $signedIn = array_keys(array_filter(ActionRegistry::all(), fn (?Access $access): bool => $access !== Access::Public));

        foreach ($signedIn as $name) {
            $this->postJson("/_actions/{$name}", [])->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHORIZED');
        }
    }
}
