<?php

namespace App\Actions;

use Attribute;

/** Declares the `Access` an action method requires. A public method of an `*Actions` class without it is not dispatchable. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(public readonly Access $access) {}
}
