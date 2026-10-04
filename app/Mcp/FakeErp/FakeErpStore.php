<?php

namespace App\Mcp\FakeErp;

use Closure;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * State of the fake ERP: the fixtures plus whatever the tools wrote. It lives
 * in one JSON file so it survives between calls (each stdio session is a new
 * process) and can be reset by deleting the file or calling reset().
 */
class FakeErpStore
{
    /** @var array<string, mixed>|null */
    private ?array $state = null;

    public function __construct(private readonly string $path) {}

    public static function fromConfig(): self
    {
        return new self((string) config('erp.fake.storage_path'));
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(string $collection): array
    {
        /** @var list<array<string, mixed>> */
        return array_values($this->state()[$collection] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(string $collection, string $id, string $label): array
    {
        foreach ($this->all($collection) as $record) {
            if (($record['id'] ?? null) === $id) {
                return $record;
            }
        }

        throw new FakeErpException("{$label} {$id} não existe.");
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function insert(string $collection, string $prefix, array $record): array
    {
        $state = $this->state();
        $next = count($state[$collection] ?? []) + 1;

        $record = ['id' => sprintf('%s-%04d', $prefix, $next), ...$record, 'created_at' => now()->toIso8601String()];
        $state[$collection][] = $record;

        $this->persist($state);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function update(string $collection, string $id, array $changes): array
    {
        $state = $this->state();

        foreach ($state[$collection] ?? [] as $index => $record) {
            if (($record['id'] ?? null) === $id) {
                $state[$collection][$index] = [...$record, ...$changes, 'updated_at' => now()->toIso8601String()];
                $this->persist($state);

                return $state[$collection][$index];
            }
        }

        throw new FakeErpException("Registo {$id} não existe.");
    }

    /**
     * Run a write once per idempotency key (section 8.3).
     *
     * @param  array<string, mixed>  $arguments
     * @param  Closure(): array<string, mixed>  $write
     * @return array<string, mixed>
     */
    public function idempotent(string $tool, string $key, array $arguments, Closure $write): array
    {
        $fingerprint = hash('sha256', (string) json_encode(Arr::sortRecursive(Arr::except($arguments, 'idempotency_key'))));
        $previous = $this->state()['idempotency'][$tool][$key] ?? null;

        if (is_array($previous)) {
            if ($previous['fingerprint'] !== $fingerprint) {
                throw new FakeErpException("A chave {$key} já foi usada em {$tool} com outros argumentos.");
            }

            return [...$previous['result'], 'idempotent_replay' => true];
        }

        $result = $write();

        $state = $this->state();
        $state['idempotency'][$tool][$key] = ['fingerprint' => $fingerprint, 'result' => $result];
        $this->persist($state);

        return [...$result, 'idempotent_replay' => false];
    }

    public function reset(): void
    {
        $this->persist(FakeErpFixtures::state());
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        if (! is_file($this->path)) {
            $this->reset();

            return $this->state ?? [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return $this->state = is_array($decoded) ? $decoded : FakeErpFixtures::state();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persist(array $state): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        file_put_contents($this->path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $this->state = $state;
    }
}
