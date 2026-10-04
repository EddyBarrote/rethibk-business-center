<?php

namespace App\Connectors;

use RuntimeException;

/**
 * A connector could not be reached or refused the call. The message is safe
 * to show to the agent and to the admin.
 */
final class ConnectorException extends RuntimeException {}
