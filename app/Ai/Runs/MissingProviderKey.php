<?php

namespace App\Ai\Runs;

use RuntimeException;

/**
 * The agent's AI provider has no API key configured.
 */
final class MissingProviderKey extends RuntimeException {}
