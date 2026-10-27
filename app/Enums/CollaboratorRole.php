<?php

namespace App\Enums;

/**
 * What an agent or a colleague does on someone's task (docs/DECISOES.md,
 * "Tarefas: colaborar com agentes e colegas").
 */
enum CollaboratorRole: string
{
    case Prepare = 'preparar';
    case Draft = 'rascunhar';
    case FollowUp = 'acompanhar';
    case Do = 'fazer';
    case Review = 'rever';

    public function label(): string
    {
        return match ($this) {
            self::Prepare => 'Preparar',
            self::Draft => 'Rascunhar',
            self::FollowUp => 'Acompanhar',
            self::Do => 'Fazer',
            self::Review => 'Rever',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Prepare => 'Junta antes o que é preciso: dados, emails, documentos.',
            self::Draft => 'Escreve a proposta, o email ou o relatório, para rever.',
            self::FollowUp => 'Faz o seguimento depois: lembretes, respostas, cobranças.',
            self::Do => 'Trata da tarefa inteira; o dono aprova.',
            self::Review => 'Lê e comenta o resultado antes de fechar.',
        };
    }

    /** The title of the sub-task: «Preparar: Proposta à Mozal». */
    public function subtaskTitle(string $title): string
    {
        return "{$this->label()}: {$title}";
    }

    /** What the agent or colleague is asked, at the top of their sub-task. */
    public function brief(): string
    {
        return match ($this) {
            self::Prepare => 'Prepare o terreno para esta tarefa: junte os dados, emails, documentos e histórico relevantes e resuma-os de forma a quem a faz poder avançar logo. Não envie nada a ninguém.',
            self::Draft => 'Escreva um rascunho do que esta tarefa pede (proposta, email, relatório ou documento) e deixe-o para revisão. Não envie nada a ninguém.',
            self::FollowUp => 'Acompanhe esta tarefa: agende os seguimentos necessários e avise quando houver resposta ou atraso. Nada sai em nome de uma pessoa sem a aprovação dela.',
            self::Do => 'Trate desta tarefa do princípio ao fim. O que tiver de sair para fora passa primeiro pela aprovação de quem a pediu.',
            self::Review => 'Reveja o trabalho desta tarefa e deixe os seus comentários.',
        };
    }

    /**
     * @return list<array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $role) => ['value' => $role->value, 'label' => $role->label(), 'hint' => $role->hint()], self::cases());
    }
}
