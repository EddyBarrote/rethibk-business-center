<?php

namespace App\Enums;

enum TaskMessageKind: string
{
    /** Conversation. */
    case Message = 'message';
    /** A direct order to execute now. */
    case Action = 'action';
    /** Status changes, delegations and other notes from the platform. */
    case Event = 'event';
    /** The result of delegated work, which wakes the delegating agent. */
    case Report = 'report';
    /** One agent passing a person's request to another, or the answer coming back (docs/DECISOES.md, "Mensagens entre agentes"). */
    case Handoff = 'handoff';
}
