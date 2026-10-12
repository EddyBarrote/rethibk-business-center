<?php

namespace App\Enums;

/**
 * Where an entry of a person's memory with an agent sits (docs/DECISOES.md,
 * "Memória em entradas", 12.10.2026), in the order the page shows them. The
 * two «Sobre si» sections reach the agent's prompt in full; the others only
 * as an index the agent opens with memory.read.
 */
enum MemorySection: string
{
    case Preferences = 'preferences';
    case Profile = 'profile';
    case Topics = 'topics';
    case Areas = 'areas';
    case People = 'people';
    case Projects = 'projects';

    public function label(): string
    {
        return match ($this) {
            self::Preferences => 'Preferências',
            self::Profile => 'Perfil',
            self::Topics => 'Temas',
            self::Areas => 'Áreas',
            self::People => 'Pessoas',
            self::Projects => 'Projectos',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Preferences => 'Como a pessoa quer que o agente trabalhe: tom, formato, horários, o que evitar.',
            self::Profile => 'Quem é a pessoa: cargo, equipa, de que trata, com quem trabalha.',
            self::Topics => 'Assuntos recorrentes: um tema por entrada (por exemplo, cobranças, fornecedores, relatórios).',
            self::Areas => 'Uma entrada por cliente, parceiro ou frente de trabalho.',
            self::People => 'Uma entrada por pessoa de quem se fala: quem é e o que interessa saber.',
            self::Projects => 'Uma entrada por projecto: objectivo, estado, prazos, o que falta.',
        };
    }

    /**
     * Preferências and Perfil, which go into the prompt in full.
     */
    public function aboutYou(): bool
    {
        return in_array($this, [self::Preferences, self::Profile], true);
    }

    /**
     * @return list<array{value: string, label: string, hint: string, about_you: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'hint' => $s->hint(), 'about_you' => $s->aboutYou()], self::cases());
    }
}
