<?php

namespace App\Actions;

use App\Admin\DepartmentAccess;
use App\Auth\AuthSession;
use App\Auth\PasswordLogin;
use App\Auth\PasswordPolicy;
use App\Auth\UserRules;
use App\Models\User;
use App\Support\Flash;
use App\Support\RequestError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/** `users.*`: Superadmins manage the Users. */
class UsersActions
{
    /** `{ name, email, role, departmentId?, password }` → `{ id }`. */
    #[Action(Access::Superadmin)]
    public function create(Request $request): array
    {
        $input = $this->validated($request, passwordRequired: true);
        $this->assertEmailFree($input['email']);
        $departmentId = $this->departmentFor($input);
        $hash = Hash::make($input['password']);

        $user = DB::transaction(function () use ($input, $departmentId, $hash): User {
            $created = (new User)->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                'role' => $input['role'],
                'department_id' => $departmentId,
            ]);
            $created->save();
            PasswordLogin::setPasswordLogin($created->id, $hash);

            return $created;
        });

        Flash::success('User created successfully.');

        return ['id' => $user->id];
    }

    /** `{ id, name, email, role, departmentId?, password? }` → `{ id }`. A new password or email ends the User's other sessions. */
    #[Action(Access::Superadmin)]
    public function update(Request $request): array
    {
        $input = $this->validated($request, passwordRequired: false, withId: true);
        $existing = User::query()->find($input['id']);
        if (! $existing) {
            throw RequestError::notFound();
        }
        $this->assertEmailFree($input['email'], $existing->id);
        $departmentId = $this->departmentFor($input);
        $hash = ($input['password'] ?? null) ? Hash::make($input['password']) : null;
        $emailChanged = $input['email'] !== mb_strtolower($existing->email);
        // An admin resetting their own password or email keeps the session they are using.
        $keep = AuthSession::currentSessionId($request);

        UserRules::superadminTransaction(function () use ($input, $existing, $departmentId, $hash, $emailChanged, $keep): void {
            if ($input['role'] !== User::ROLE_SUPERADMIN) {
                UserRules::assertUserDemotable($existing->id);
            }
            $existing->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
                'role' => $input['role'],
                'department_id' => $departmentId,
            ])->save();
            // A reset password must also end every session that may belong to whoever knew the old one; so must
            // a new email address (the password-reset target).
            if ($hash !== null) {
                PasswordLogin::setPasswordLogin($existing->id, $hash, ['endSessions' => ['except' => $keep]]);
            } elseif ($emailChanged) {
                PasswordLogin::endSessions($existing->id, $keep);
            }
        });

        Flash::success('User updated successfully.');

        return ['id' => $existing->id];
    }

    /** `{ id }` → `{ id }`. */
    #[Action(Access::Superadmin)]
    public function delete(Request $request): array
    {
        $actor = $request->user();
        $input = $request->validate(['id' => ['required', 'integer']]);
        $id = (int) $input['id'];
        if ($id === $actor->id) {
            throw RequestError::badRequest('Cannot delete the currently signed-in user.');
        }
        UserRules::deleteUserWithFiles($id);
        Flash::success('User deleted successfully.');

        return ['id' => $id];
    }

    /** @return array{id?: int, name: string, email: string, role: string, departmentId?: int|null, password?: string|null} */
    private function validated(Request $request, bool $passwordRequired, bool $withId = false): array
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : null,
            'email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : null,
        ]);
        $password = ['string', 'min:'.PasswordPolicy::MIN_PASSWORD_LENGTH];

        return $request->validate([
            ...($withId ? ['id' => ['required', 'integer']] : []),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
            'role' => ['required', Rule::in([User::ROLE_SUPERADMIN, User::ROLE_DEPARTMENT_USER])],
            'departmentId' => [
                'nullable', 'integer',
                Rule::requiredIf(fn () => $request->input('role') === User::ROLE_DEPARTMENT_USER),
            ],
            'password' => $passwordRequired ? ['required', ...$password] : ['nullable', ...$password],
        ], [
            'name.required' => 'The name is required.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'role.required' => 'Choose a role.',
            'role.in' => 'Choose a role.',
            'departmentId.required' => 'Choose a department for department users.',
            'password.required' => 'The password is required.',
            'password.min' => PasswordPolicy::passwordTooShort(),
        ]);
    }

    private function assertEmailFree(string $email, ?int $exceptId = null): void
    {
        $taken = User::query()->where('email', $email)->first(['id']);
        if ($taken && $taken->id !== $exceptId) {
            throw RequestError::conflict('This email address is already in use.');
        }
    }

    /** Department to store for the role: only department users have one, and it must exist. */
    private function departmentFor(array $input): ?int
    {
        if ($input['role'] !== User::ROLE_DEPARTMENT_USER) {
            return null;
        }
        $departmentId = (int) $input['departmentId'];
        DepartmentAccess::assertDepartmentExists($departmentId);

        return $departmentId;
    }
}
