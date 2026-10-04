<?php

namespace Database\Factories;

use App\Models\GeneratedDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'title' => 'Relatório '.fake()->words(2, true),
            'format' => 'pdf',
            'template' => 'documento',
            'source' => "# Relatório\n\nTexto.",
            'disk' => 'local',
            'path' => 'documents/teste.pdf',
            'filename' => 'relatorio.pdf',
            'size_bytes' => 1024,
        ];
    }
}
