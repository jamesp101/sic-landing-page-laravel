<?php

namespace Tests\Unit\Platform;

use App\Actions\ActionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * `fixtures/actions.json` lists every `module.action` of `ActionRegistry::all()`. `resources/js/lib/parity/action-names.parity.test.ts`
 * holds the TS `actions` client's names to the same file, so a misspelled or missing action name fails on one side or the other.
 */
final class ActionNamesParityTest extends TestCase
{
    public function test_fixture_lists_exactly_the_registered_actions(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/actions.json'), true, 512, JSON_THROW_ON_ERROR)['actions'];

        $registered = [];
        foreach (array_keys(ActionRegistry::all()) as $name) {
            [$module, $action] = explode('.', $name);
            $registered[$module][] = $action;
        }

        $this->assertSame($registered, $fixture, 'Update tests/Unit/Platform/fixtures/actions.json and resources/js/lib/action-names.ts to match ActionRegistry::all().');
    }
}
