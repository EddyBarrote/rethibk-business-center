<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

#[Signature('tenant:create {name : Nome da organização} {slug : Subdomínio, ex. micomoc} {--domain= : Domínio próprio (opcional)} {--owner-name= : Nome do proprietário} {--owner-email= : Email do proprietário}')]
#[Description('Cria um tenant e o seu utilizador proprietário')]
class CreateTenant extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $data = [
            'name' => $this->argument('name'),
            'slug' => Str::lower((string) $this->argument('slug')),
            'domain' => $this->option('domain') ? Str::lower((string) $this->option('domain')) : null,
            'owner_name' => $this->option('owner-name') ?? $this->ask('Nome do proprietário'),
            'owner_email' => Str::lower((string) ($this->option('owner-email') ?? $this->ask('Email do proprietário'))),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:63', 'unique:tenants,slug'],
            'domain' => ['nullable', 'string', 'max:255', 'unique:tenants,domain'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = password('Palavra-passe do proprietário', required: true, validate: fn (string $value) => strlen($value) < 8 ? 'Mínimo de 8 caracteres.' : null);

        $tenant = DB::transaction(function () use ($data, $password, $tenants) {
            $tenant = Tenant::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'domain' => $data['domain'],
                'settings' => [],
            ]);

            $tenants->run($tenant, fn () => User::query()->create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => $password,
                'role' => Role::Owner,
            ]));

            return $tenant;
        });

        $this->info("Tenant {$tenant->slug} criado (id {$tenant->id}).");

        return self::SUCCESS;
    }
}
