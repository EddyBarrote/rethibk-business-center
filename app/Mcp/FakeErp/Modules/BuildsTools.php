<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\FakeErpTool;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

trait BuildsTools
{
    /**
     * @param  Closure(JsonSchema): array<string, Type>  $arguments
     * @param  Closure(array<string, mixed>, FakeErpStore): array<string, mixed>  $handler
     */
    private function read(string $name, string $description, Closure $arguments, Closure $handler): FakeErpTool
    {
        return new FakeErpTool($name, $this->title($name), $description, false, $arguments, $handler);
    }

    /**
     * @param  Closure(JsonSchema): array<string, Type>  $arguments
     * @param  Closure(array<string, mixed>, FakeErpStore): array<string, mixed>  $handler
     */
    private function write(string $name, string $description, Closure $arguments, Closure $handler): FakeErpTool
    {
        return new FakeErpTool($name, $this->title($name), $description, true, $arguments, $handler);
    }

    /**
     * Validation errors become readable isError results.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $arguments, array $rules): array
    {
        return Validator::make($arguments, $rules)->validate();
    }

    /**
     * Case and accent insensitive "contains".
     */
    private function matches(?string $haystack, string $needle): bool
    {
        return $needle === '' || Str::contains(Str::ascii((string) $haystack), Str::ascii($needle), ignoreCase: true);
    }

    private function title(string $name): string
    {
        return Str::headline(str_replace('.', ' ', $name));
    }
}
