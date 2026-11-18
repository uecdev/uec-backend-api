<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Email;
use App\Support\Permissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('uec:admin:create {--email= : Admin email} {--name= : Display name} {--role=super_admin : Role to assign} {--password= : Password (prompted if omitted)}')]
#[Description('Create (or promote) a dashboard administrator')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = Email::normalize($this->option('email') ?: $this->ask('Email address'));
        $name = $this->option('name') ?: $this->ask('Name', 'UEC Administrator');
        $role = (string) $this->option('role');
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters, letters, numbers and symbols)');

        $validator = Validator::make(compact('email', 'password', 'role'), [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
            'role' => ['required', 'in:'.implode(',', config('uec.admin.roles'))],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $user->name ?: $name,
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'suspended_at' => null,
        ])->save();
        $user->assignRole($role);

        $this->info(sprintf('%s is now a %s.', $email, Permissions::ROLE_LABELS[$role] ?? $role));

        return self::SUCCESS;
    }
}
