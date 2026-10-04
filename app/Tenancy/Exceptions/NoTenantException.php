<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class NoTenantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant is set for this operation.');
    }
}
