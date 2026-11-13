<?php

namespace App\Actions;

use App\Models\User;
use App\Support\Authz;

/**
 * Who may call an action, declared once per action method with `#[Action(Access::…)]` and enforced by
 * `ActionController` before the body (and its input validation) runs. The Department-user rule (which rows a
 * non-Superadmin may touch) stays in the body: it needs the loaded rows (`DepartmentAccess`).
 */
enum Access: string
{
    /** Signed-out visitors included. */
    case Public = 'public';

    /** Any signed-in User. */
    case User = 'user';

    /** Superadmins only. */
    case Superadmin = 'superadmin';

    /** Throws the 401/403 `RequestError` when `$actor` may not call an action with this access. */
    public function enforce(?User $actor): void
    {
        match ($this) {
            self::Public => null,
            self::User => Authz::requireUser($actor),
            self::Superadmin => Authz::requireSuperAdmin($actor),
        };
    }
}
