<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

#[Signature('admin:create {email : Email do operador} {--name= : Nome}')]
#[Description('Cria um super admin (operador da Rethink) para a consola de administração')]
class CreatePlatformAdmin extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? $this->ask('Nome'),
            'email' => Str::lower((string) $this->argument('email')),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:platform_admins,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = password('Palavra-passe', required: true, validate: fn (string $value) => strlen($value) < 12 ? 'Mínimo de 12 caracteres.' : null);

        PlatformAdmin::query()->create([...$data, 'password' => $password, 'is_active' => true]);

        $this->components->info("Super admin {$data['email']} criado. Entra em ".config('tenancy.admin_domain').'.');

        return self::SUCCESS;
    }
}
