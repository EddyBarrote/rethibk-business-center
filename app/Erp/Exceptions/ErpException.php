<?php

namespace App\Erp\Exceptions;

use RuntimeException;

/**
 * The ERP could not be reached or did not answer as MCP (as opposed to a tool
 * answering with isError, which is an ErpCallResult with ok = false).
 */
class ErpException extends RuntimeException {}
