<?php

namespace App\Auth;

use App\Models\User;
use App\Storage\StoredFiles;
use App\Support\RequestError;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Rules about the set of Users that no single action owns.
 *
 * Last Superadmin: every path that can remove one (deleting a User, demoting one) runs its check and its write in
 * ONE superadminTransaction. On MySQL/MariaDB that transaction is SERIALIZABLE: two concurrent removals that both
 * counted "another Superadmin exists" cannot both commit, the database aborts one with a serialization failure or
 * deadlock and it reaches the user as a 409 "try again" instead of a 500. (SQLite runs one writer at a time, so
 * there the level changes nothing; the tests cover the shared transaction and the sequential cases.)
 */
final class UserRules
{
    /**
     * Runs `$run` in one transaction (SERIALIZABLE where the database supports it). A serialization
     * failure/deadlock becomes a 409 RequestError; anything else, including a RequestError from `$run`, passes through.
     *
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    public static function superadminTransaction(callable $run): mixed
    {
        try {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && DB::transactionLevel() === 0) {
                // Applies to the next transaction only.
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }

            return DB::transaction($run, 1);
        } catch (DeadlockException $error) {
            throw self::conflict();
        } catch (QueryException $error) {
            // 40001: serialization failure (SQLSTATE), reported by every provider.
            if ($error->getCode() === '40001') {
                throw self::conflict();
            }
            throw $error;
        }
    }

    /** True when `$userId` is a Superadmin and no other Superadmin exists. */
    private static function isLastSuperadmin(int $userId): bool
    {
        $role = User::query()->whereKey($userId)->value('role');
        if ($role !== User::ROLE_SUPERADMIN) {
            return false;
        }

        return ! User::query()->where('role', User::ROLE_SUPERADMIN)->whereKeyNot($userId)->exists();
    }

    /** Throws FORBIDDEN when deleting `$userId` would remove the last Superadmin. Call inside superadminTransaction. */
    public static function assertUserDeletable(int $userId): void
    {
        if (self::isLastSuperadmin($userId)) {
            throw RequestError::forbidden('The last superadmin account cannot be deleted.');
        }
    }

    /** Throws FORBIDDEN when changing `$userId` to a department user would remove the last Superadmin. Call inside superadminTransaction. */
    public static function assertUserDemotable(int $userId): void
    {
        if (self::isLastSuperadmin($userId)) {
            throw RequestError::forbidden('The last superadmin cannot be changed to a department user.');
        }
    }

    /**
     * Deletes a User (sessions go with them) and, after the commit, the files they own: Highlight images of their
     * posts and their downloads. The last-Superadmin check and the delete share one transaction. NOT_FOUND when
     * the User does not exist.
     */
    public static function deleteUserWithFiles(int $userId): void
    {
        $user = User::query()->find($userId) ?? throw RequestError::notFound();
        StoredFiles::delete($user, function () use ($userId): void {
            self::superadminTransaction(function () use ($userId): void {
                self::assertUserDeletable($userId);
                PasswordLogin::endSessions($userId);
                User::query()->whereKey($userId)->delete();
            });
        });
    }

    private static function conflict(): RequestError
    {
        return RequestError::conflict('Another change happened at the same time. Try again.');
    }
}
