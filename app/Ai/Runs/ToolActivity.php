<?php

namespace App\Ai\Runs;

/**
 * What the agent is doing, in plain Portuguese, for the line under its name
 * while it works: «Pesquisar na internet» becomes «A pesquisar na internet»
 * and «Contratos» becomes «A consultar contratos» (docs/DECISOES.md,
 * "Raciocínio na conversa").
 */
final class ToolActivity
{
    public static function phrase(string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            return 'A usar uma ferramenta';
        }

        $first = mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
        $verb = mb_strtolower((string) strtok($label, ' '));

        // An infinitive (pesquisar, ler, redigir, pôr) reads as what it is doing.
        return preg_match('/(ar|er|ir|ôr)$/u', $verb) === 1
            ? "A {$first}"
            : "A consultar {$first}";
    }
}
