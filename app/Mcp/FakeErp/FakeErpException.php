<?php

namespace App\Mcp\FakeErp;

use RuntimeException;

/**
 * A business error from the fake ERP. Its message reaches the client as a
 * readable isError result (section 8.3), never as a stack trace.
 */
class FakeErpException extends RuntimeException {}
