<?php

namespace App\Enums;

/**
 * What a notification is about. Stored in the `type` column of `notifications`
 * (PlatformNotice::databaseType), so the centre filters and counts by it.
 */
enum NotificationCategory: string
{
    case Approval = 'approval';
    case Waiting = 'waiting';
    case Task = 'task';
    case Conversation = 'conversation';
    case Email = 'email';
    case Deadline = 'deadline';
    case Document = 'document';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Approval => 'Aprovações',
            self::Waiting => 'À sua espera',
            self::Task => 'Tarefas',
            self::Conversation => 'Conversas',
            self::Email => 'Email',
            self::Deadline => 'Prazos e lembretes',
            self::Document => 'Documentos',
            self::Agent => 'Avisos dos agentes',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Approval => 'Acções acima do nível de um agente que só uma pessoa pode decidir: pagamentos, contratos, orçamento.',
            self::Waiting => 'Um agente precisa de uma resposta sua, ou uma tarefa está pronta para rever.',
            self::Task => 'Tarefas que lhe atribuíram, de pessoas, de agentes ou de um fluxo de trabalho, e as que foram concluídas.',
            self::Conversation => 'Respostas nas suas conversas e agentes que querem falar consigo.',
            self::Email => 'Emails encaminhados para si e pedidos de clientes fora do SLA.',
            self::Deadline => 'Prazos de emails e concursos, contratos a terminar e seguimentos marcados.',
            self::Document => 'Briefings, documentos para rever e novidades na base de conhecimento.',
            self::Agent => 'O que um agente lhe quis dizer, fora das categorias acima.',
        };
    }

    /**
     * What a person gets by default: everything in the centre, an alert on the
     * screen for what waits on them, and approvals by email too.
     *
     * @return array{app: bool, toast: bool, email: bool}
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Approval => ['app' => true, 'toast' => true, 'email' => true],
            self::Waiting, self::Task, self::Conversation => ['app' => true, 'toast' => true, 'email' => false],
            default => ['app' => true, 'toast' => false, 'email' => false],
        };
    }

    /** Approvals are always in the centre: payments and contracts need a person. */
    public function required(): bool
    {
        return $this === self::Approval;
    }

    /**
     * Notifications from before the categories: told apart by their link and title.
     *
     * @param  array<string, mixed>  $data
     */
    public static function infer(array $data): self
    {
        $url = (string) ($data['url'] ?? '');
        $title = (string) ($data['title'] ?? '');

        return match (true) {
            str_starts_with($url, '/approvals') => self::Approval,
            str_contains($title, 'pronta para rever'), str_contains($title, 'está à sua espera') => self::Waiting,
            str_contains($title, 'quer falar consigo'), str_ends_with($title, 'respondeu') => self::Conversation,
            str_starts_with($url, '/tasks') => self::Task,
            str_starts_with($title, 'Prazo a chegar'), str_starts_with($title, 'Concurso a fechar'),
            str_starts_with($title, 'Contrato a terminar'), str_starts_with($title, 'Seguimento:') => self::Deadline,
            str_starts_with($url, '/inbox') => self::Email,
            str_starts_with($url, '/briefings'), str_starts_with($url, '/reports'), str_starts_with($url, '/knowledge') => self::Document,
            default => self::Agent,
        };
    }
}
