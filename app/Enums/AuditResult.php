<?php

namespace App\Enums;

enum AuditResult: string
{
    case Ok = 'ok';
    case Denied = 'denied';
    case Error = 'error';
}
