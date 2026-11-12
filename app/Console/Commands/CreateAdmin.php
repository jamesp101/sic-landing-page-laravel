<?php

namespace App\Console\Commands;

use App\Auth\PasswordLogin;
use App\Auth\PasswordPolicy;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a Superadmin, or resets the password of an existing User and makes them Superadmin.
 *
 *   ADMIN_EMAIL=you@example.com ADMIN_NAME="Your Name" [ADMIN_PASSWORD=…] php artisan sic:create-admin
 *
 * Without ADMIN_PASSWORD the password is prompted on a terminal (not echoed). It is never printed.
 *
 * `--if-missing` (run by every deploy) does nothing once a Superadmin exists, and only warns when no Superadmin
 * exists and ADMIN_EMAIL is unset, so a deploy creates the initial Superadmin from `.env` exactly once.
 */
class CreateAdmin extends Command
{
    protected $signature = 'sic:create-admin {--if-missing : Only when no Superadmin exists yet; skip quietly without ADMIN_EMAIL}';

    protected $description = 'Create a Superadmin, or reset an existing User\'s password and make them Superadmin (ADMIN_EMAIL, ADMIN_NAME, ADMIN_PASSWORD)';

    public function handle(): int
    {
        if ($this->option('if-missing')) {
            if (User::query()->where('role', User::ROLE_SUPERADMIN)->exists()) {
                $this->info('A superadmin exists; nothing to do.');

                return self::SUCCESS;
            }
            if (trim((string) env('ADMIN_EMAIL')) === '') {
                $this->warn('No superadmin exists. Set ADMIN_EMAIL, ADMIN_NAME and ADMIN_PASSWORD in .env and deploy again, or run sic:create-admin.');

                return self::SUCCESS;
            }
        }

        $email = mb_strtolower(trim((string) env('ADMIN_EMAIL')));
        $name = trim((string) env('ADMIN_NAME'));
        if (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return $this->failure('Set ADMIN_EMAIL to a valid email address.');
        }

        $existing = User::query()->where('email', $email)->first();
        if (! $existing && $name === '') {
            return $this->failure('Set ADMIN_NAME to create a new user.');
        }

        $password = $this->readPassword();
        if ($password === null) {
            return self::FAILURE;
        }
        if (mb_strlen($password) < PasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH) {
            return $this->failure(PasswordPolicy::passwordTooShort(PasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH));
        }
        $hash = Hash::make($password);

        $user = DB::transaction(function () use ($existing, $name, $email, $hash): User {
            $user = $existing ?? new User;
            $user->forceFill([
                'role' => User::ROLE_SUPERADMIN,
                'department_id' => null,
                'email_verified' => true,
                'email_verified_at' => now(),
                'email' => $email,
                ...($name !== '' ? ['name' => $name] : []),
            ])->save();
            // A reset must sign out sessions opened with the old password.
            PasswordLogin::setPasswordLogin($user->id, $hash, ['endSessions' => []]);

            return $user;
        });

        $this->info(($existing ? 'Updated' : 'Created')." superadmin {$user->email} (id {$user->id}).");

        return self::SUCCESS;
    }

    private function readPassword(): ?string
    {
        $fromEnv = (string) env('ADMIN_PASSWORD');
        if ($fromEnv !== '') {
            return $fromEnv;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Set ADMIN_PASSWORD or run on a terminal to be prompted.');

            return null;
        }
        $first = (string) $this->secret('Password');
        $second = (string) $this->secret('Repeat password');
        if ($first !== $second) {
            $this->error('Passwords do not match.');

            return null;
        }

        return $first;
    }

    private function failure(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
