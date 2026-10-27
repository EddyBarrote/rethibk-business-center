<?php

namespace App\Enums;

/** What a standing approval rule does with an action (docs/DECISOES.md, "Regras de aprovação"). */
enum RuleEffect: string
{
    case Allow = 'allow';
    case Ask = 'ask';
    case Deny = 'deny';

    public function label(): string
    {
        return match ($this) {
            self::Allow => 'Permitir sempre',
            self::Ask => 'Perguntar sempre',
            self::Deny => 'Recusar sempre',
        };
    }
}
