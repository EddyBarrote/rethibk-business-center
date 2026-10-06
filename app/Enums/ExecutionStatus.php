<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case NotExecuted = 'not_executed';
    case Executed = 'executed';
    case Failed = 'failed';
}
