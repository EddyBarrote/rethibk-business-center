<?php

namespace App\Mcp\FakeErp;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * One tool of the fake ERP, built from a definition in a module instead of a
 * class per tool, so the whole section 8.3 surface reads as one catalogue.
 *
 * Write tools require an idempotency_key: a repeated key with the same
 * arguments returns the first result; with different arguments it fails.
 */
class FakeErpTool extends Tool
{
    /**
     * @param  Closure(JsonSchema): array<string, Type>  $arguments
     * @param  Closure(array<string, mixed>, FakeErpStore): array<string, mixed>  $handler
     */
    public function __construct(
        string $name,
        string $title,
        string $description,
        public readonly bool $writes,
        private readonly Closure $arguments,
        private readonly Closure $handler,
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
    }

    public function handle(Request $request, FakeErpStore $store): Response|ResponseFactory
    {
        $arguments = $request->all();

        try {
            if (! $this->writes) {
                return Response::structured(($this->handler)($arguments, $store));
            }

            $key = $arguments['idempotency_key'] ?? null;

            if (! is_string($key) || trim($key) === '') {
                throw new FakeErpException('O campo idempotency_key é obrigatório em ferramentas que escrevem.');
            }

            return Response::structured($store->idempotent(
                $this->name(),
                $key,
                $arguments,
                fn () => ($this->handler)($arguments, $store),
            ));
        } catch (FakeErpException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        $properties = ($this->arguments)($schema);

        if ($this->writes) {
            $properties['idempotency_key'] = $schema->string()
                ->description('Chave única da operação. Repetir a chave devolve o primeiro resultado sem voltar a escrever.')
                ->required();
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return $this->writes
            ? ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true]
            : ['readOnlyHint' => true];
    }
}
