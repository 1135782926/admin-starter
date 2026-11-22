<?php

namespace App\Console\Commands;

use App\Models\AdminRole;
use App\Models\AdminUser;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Question\Question;

#[Signature('admin:bootstrap {username : Administrator login name} {--reset-password : Reset an existing enabled administrator and revoke their tokens}')]
#[Description('Create the first enabled super administrator or recover an administrator account')]
class BootstrapAdmin extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to enter the password.');

            return self::FAILURE;
        }

        $username = trim($this->argument('username'));
        $reset = $this->option('reset-password');

        try {
            Validator::make(['username' => $username], [
                'username' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/'],
            ])->validate();

            $passwordHash = Hash::make($this->promptForPassword());

            if ($reset) {
                $this->resetPassword($username, $passwordHash);
            } else {
                $this->createSuperAdmin($username, $passwordHash);
            }
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (UniqueConstraintViolationException $exception) {
            if (! AdminUser::query()->where('username', $username)->exists()) {
                throw $exception;
            }

            $this->error('That administrator username is already in use.');

            return self::FAILURE;
        }

        $this->info($reset
            ? 'Administrator password reset. Existing tokens were revoked.'
            : 'Super administrator created.');

        return self::SUCCESS;
    }

    private function promptForPassword(): string
    {
        $credentials = Validator::make([
            'password' => $this->readSecret('New password'),
            'password_confirmation' => $this->readSecret('Confirm new password'),
        ], [
            'password' => [
                'bail', 'required', 'string', 'min:12', 'max:255', 'confirmed', 'not_regex:/\x00/',
                function (string $attribute, string $value, Closure $fail): void {
                    if (config('hashing.driver') === 'bcrypt') {
                        $maxBytes = min(72, (int) (config('hashing.bcrypt.limit') ?: 72));
                        if (strlen($value) > $maxBytes) {
                            $fail("The password must not exceed {$maxBytes} bytes.");
                        }
                    }
                },
            ],
        ])->validate();

        return $credentials['password'];
    }

    private function readSecret(string $prompt): ?string
    {
        $question = (new Question($prompt))->setHidden(true)->setHiddenFallback(false)->setTrimmable(false);

        try {
            $answer = $this->output->askQuestion($question);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['password' => 'Password input was unavailable. Run this command in an interactive terminal.']);
        }

        return $answer === null ? null : rtrim($answer, "\r\n");
    }

    private function createSuperAdmin(string $username, string $passwordHash): void
    {
        DB::transaction(function () use ($username, $passwordHash): void {
            $roleAttributes = ['name' => 'super-admin', 'guard_name' => 'web'];
            $role = AdminRole::query()->where($roleAttributes)->lockForUpdate()->first()
                ?? AdminRole::query()->createOrFirst($roleAttributes)->refreshForUpdate();

            if (! $role->status || $role->parent_id !== null) {
                throw ValidationException::withMessages(['role' => 'The reserved super-admin role must be enabled and at the root.']);
            }

            if (AdminUser::query()->where('status', true)->role($role)->exists()) {
                throw ValidationException::withMessages(['role' => 'An enabled super administrator already exists. Use --reset-password to recover an account.']);
            }

            $admin = AdminUser::create([
                'username' => $username,
                'nickname' => $username,
                'password' => $passwordHash,
            ]);
            $admin->assignRole($role);
        }, attempts: 3);
    }

    private function resetPassword(string $username, string $passwordHash): void
    {
        DB::transaction(function () use ($username, $passwordHash): void {
            $admin = AdminUser::query()->where('username', $username)->where('status', true)->lockForUpdate()->first();
            if (! $admin) {
                throw ValidationException::withMessages(['username' => 'No enabled administrator has that username.']);
            }

            $admin->password = $passwordHash;
            $admin->setRememberToken(null);
            $admin->save();
            $admin->tokens()->delete();
        }, attempts: 3);
    }
}
