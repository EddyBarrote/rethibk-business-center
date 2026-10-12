<?php

namespace App\Enums;

/**
 * The fixed sections of the company memory (docs/DECISOES.md, 12.10.2026),
 * in the order agents read them.
 */
enum CompanySection: string
{
    case About = 'about';
    case Clients = 'clients';
    case Rules = 'rules';
    case Pricing = 'pricing';
    case People = 'people';
    case Decisions = 'decisions';

    public function label(): string
    {
        return match ($this) {
            self::About => 'Quem somos',
            self::Clients => 'Clientes e parceiros',
            self::Rules => 'Regras e políticas',
            self::Pricing => 'Preços e condições',
            self::People => 'Pessoas e papéis',
            self::Decisions => 'Decisões',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::About => 'O que a empresa faz, onde está, como se apresenta.',
            self::Clients => 'Clientes-chave, fornecedores e parceiros, com as condições que valem sempre.',
            self::Rules => 'Políticas e procedimentos que todos seguem.',
            self::Pricing => 'Tabelas, margens, prazos de pagamento e condições comerciais.',
            self::People => 'Quem decide o quê e a quem se pede cada coisa.',
            self::Decisions => 'O que a direcção decidiu e continua em vigor.',
        };
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'hint' => $s->hint()], self::cases());
    }
}
