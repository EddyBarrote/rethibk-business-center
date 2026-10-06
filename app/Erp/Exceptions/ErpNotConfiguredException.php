<?php

namespace App\Erp\Exceptions;

class ErpNotConfiguredException extends ErpException
{
    public function __construct()
    {
        parent::__construct('Este tenant não tem ligação ao ERP configurada.');
    }
}
