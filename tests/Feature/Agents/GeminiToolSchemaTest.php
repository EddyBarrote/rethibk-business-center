<?php

use App\Ai\Agents\ToolResolver;
use App\Ai\Runs\AgentRunner;
use App\Ai\Skills\SkillContext;
use App\Ai\Templates\AgentTemplates;
use App\Enums\TriggerType;
use App\Models\Tenant;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\Gemini\Concerns\MapsTools;

// Gemini only accepts a subset of JSON Schema for function parameters: an
// object must declare its properties and an array its items. One bad skill
// makes every run of every agent that has it fail, so check them all.

beforeEach(fn () => $this->store = freshFakeErp());

afterEach(fn () => @unlink($this->store));

it('gives Gemini a valid declaration for every skill of every template agent', function () {
    $mapper = new class
    {
        use MapsTools;

        public function declaration(Tool $tool): array
        {
            return $this->mapTool($tool);
        }
    };

    $problems = [];
    $walk = function (array $schema, string $path) use (&$walk, &$problems): void {
        $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'maxItems', 'minItems', 'properties', 'required', 'items', 'minimum', 'maximum', 'anyOf', 'title', 'minLength', 'maxLength', 'pattern', 'default'];

        foreach (array_diff(array_keys($schema), $allowed) as $keyword) {
            $problems[] = "{$path}: {$keyword}";
        }

        match (true) {
            is_array($schema['type'] ?? null) => $problems[] = "{$path}: type is a list",
            ($schema['type'] ?? null) === 'object' && empty($schema['properties']) => $problems[] = "{$path}: object without properties",
            ($schema['type'] ?? null) === 'array' && ! isset($schema['items']) => $problems[] = "{$path}: array without items",
            default => null,
        };

        foreach ($schema['properties'] ?? [] as $name => $property) {
            $walk($property, "{$path}.{$name}");
        }

        if (isset($schema['items'])) {
            $walk($schema['items'], "{$path}[]");
        }
    };

    $count = asTenant(Tenant::factory()->create(), function () use ($mapper, $walk) {
        $count = 0;

        foreach (array_keys(AgentTemplates::all()) as $template) {
            $agent = templateAgent($template);
            $run = app(AgentRunner::class)->create($agent, 'teste', TriggerType::Manual);

            foreach (app(ToolResolver::class)->for(new SkillContext($agent, $run)) as $tool) {
                $declaration = $mapper->declaration($tool);
                $count++;

                if (isset($declaration['parameters'])) {
                    $walk($declaration['parameters'], $declaration['name']);
                }
            }
        }

        return $count;
    });

    expect($count)->toBeGreaterThan(100)
        ->and(array_values(array_unique($problems)))->toBe([]);
});
