<?php

namespace App\Enums;

enum CapabilitySource: string
{
    /** PHP code in App\Ai\Capabilities\Local. */
    case Local = 'local';

    /** A tool of the tenant's ERP, over MCP. */
    case Mcp = 'mcp';

    /** A tool of a connector: a remote MCP server or an HTTP action. */
    case Connector = 'connector';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Plataforma',
            self::Mcp => 'ERP',
            self::Connector => 'Conector',
        };
    }
}
