<?php

namespace App\Enums;

enum ConnectorKind: string
{
    /** A remote MCP server (streamable HTTP): each of its tools becomes a capability. */
    case Mcp = 'mcp';

    /** One HTTP endpoint: the agent's arguments go as JSON (or query string for GET). */
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Mcp => 'Servidor MCP',
            self::Http => 'Pedido HTTP',
        };
    }
}
