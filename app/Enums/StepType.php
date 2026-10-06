<?php

namespace App\Enums;

enum StepType: string
{
    case Message = 'message';
    case Reasoning = 'reasoning';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Approval = 'approval';
    case Error = 'error';
}
