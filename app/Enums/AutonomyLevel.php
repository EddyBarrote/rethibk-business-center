<?php

namespace App\Enums;

/**
 * The autonomy ladder of section 12.1.
 */
enum AutonomyLevel: int
{
    case Observe = 0;
    case Suggest = 1;
    case ExecuteWithApproval = 2;
    case ExecuteWithinLimits = 3;
    case ExecuteAndReport = 4;

    public function code(): string
    {
        return 'N'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Observe => 'Observa e organiza',
            self::Suggest => 'Sugere',
            self::ExecuteWithApproval => 'Executa com aprovação',
            self::ExecuteWithinLimits => 'Executa dentro de limites',
            self::ExecuteAndReport => 'Executa e reporta',
        };
    }

    /**
     * @return list<array{value: int, code: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $level) => ['value' => $level->value, 'code' => $level->code(), 'label' => $level->label()], self::cases());
    }
}
