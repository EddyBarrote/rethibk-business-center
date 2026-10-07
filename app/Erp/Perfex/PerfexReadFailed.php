<?php

namespace App\Erp\Perfex;

use RuntimeException;

/**
 * A Perfex read behind a contract answer came back with an error.
 */
final class PerfexReadFailed extends RuntimeException {}
